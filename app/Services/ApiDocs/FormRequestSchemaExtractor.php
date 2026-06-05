<?php

namespace App\Services\ApiDocs;

use Illuminate\Validation\Rules\In;
use ReflectionClass;

class FormRequestSchemaExtractor
{
    /**
     * Extract OpenAPI schema from a Form Request class.
     *
     * @param  string  $formRequestClass
     * @return array{type: string, properties: array, required: array}
     */
    public function extract(string $formRequestClass): array
    {
        if (! class_exists($formRequestClass)) {
            return $this->emptySchema();
        }

        try {
            // Use reflection to create instance and call rules() method
            $reflection = new ReflectionClass($formRequestClass);

            if (! $reflection->hasMethod('rules')) {
                return $this->emptySchema();
            }

            // Create instance without constructor dependencies
            $request = $reflection->newInstanceWithoutConstructor();

            // Call the rules method
            $rulesMethod = $reflection->getMethod('rules');
            $rulesMethod->setAccessible(true);

            // Some rules methods need request context, so we try both approaches
            try {
                $rules = $rulesMethod->invoke($request);
            } catch (\Throwable) {
                // Fallback: try with a fresh request instance from container
                $request = app($formRequestClass);
                $rules = $request->rules();
            }
        } catch (\Throwable) {
            return $this->emptySchema();
        }

        return $this->convertRulesToSchema($rules);
    }

    /**
     * Convert Laravel validation rules to OpenAPI schema.
     */
    public function convertRulesToSchema(array $rules): array
    {
        $properties = [];
        $required = [];
        $nestedArrays = [];

        // First pass: identify nested array structures
        foreach ($rules as $field => $fieldRules) {
            if (str_contains($field, '.*.')) {
                // This is a nested array item property like 'events.*.event_type'
                $parts = explode('.*.', $field, 2);
                $arrayField = $parts[0];
                $nestedProperty = $parts[1];

                if (! isset($nestedArrays[$arrayField])) {
                    $nestedArrays[$arrayField] = ['properties' => [], 'required' => []];
                }

                $schema = $this->convertFieldRulesToSchema($fieldRules);
                $nestedArrays[$arrayField]['properties'][$nestedProperty] = $schema;

                if ($this->isRequired($fieldRules)) {
                    $nestedArrays[$arrayField]['required'][] = $nestedProperty;
                }
                continue;
            }

            if (str_contains($field, '.*')) {
                // Array item type like 'events.*' or 'secondary_keywords.*'
                // Will be handled when processing the parent array
                continue;
            }
        }

        // Second pass: process regular fields and arrays
        foreach ($rules as $field => $fieldRules) {
            if (str_contains($field, '.')) {
                // Skip nested fields - already processed above
                continue;
            }

            $schema = $this->convertFieldRulesToSchema($fieldRules);

            // Check if this is an array with nested object structure
            if (isset($nestedArrays[$field])) {
                $schema['type'] = 'array';
                $schema['items'] = [
                    'type' => 'object',
                    'properties' => $nestedArrays[$field]['properties'],
                ];
                if (! empty($nestedArrays[$field]['required'])) {
                    $schema['items']['required'] = $nestedArrays[$field]['required'];
                }
            } elseif ($schema['type'] === 'array' && isset($rules[$field.'.*'])) {
                // Simple array with item type
                $itemSchema = $this->convertFieldRulesToSchema($rules[$field.'.*']);
                $schema['items'] = $itemSchema;
            }

            $properties[$field] = $schema;

            if ($this->isRequired($fieldRules)) {
                $required[] = $field;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Convert a single field's validation rules to OpenAPI schema.
     */
    public function convertFieldRulesToSchema(array|string $rules): array
    {
        $rules = is_array($rules) ? $rules : explode('|', $rules);
        $schema = ['type' => 'string']; // Default

        foreach ($rules as $rule) {
            $this->applyRule($rule, $schema);
        }

        return $schema;
    }

    /**
     * Apply a single rule to the schema.
     */
    protected function applyRule(mixed $rule, array &$schema): void
    {
        // Handle Rule objects
        if ($rule instanceof In) {
            $reflection = new ReflectionClass($rule);
            $property = $reflection->getProperty('values');
            $property->setAccessible(true);
            $schema['enum'] = $property->getValue($rule);

            return;
        }

        if (is_object($rule)) {
            // Other rule objects we can't parse
            return;
        }

        if (! is_string($rule)) {
            return;
        }

        // Handle string rules
        match (true) {
            $rule === 'integer' => $schema['type'] = 'integer',
            $rule === 'boolean' || $rule === 'bool' => $schema['type'] = 'boolean',
            $rule === 'numeric' => $schema['type'] = 'number',
            $rule === 'array' => $schema['type'] = 'array',
            $rule === 'string' => $schema['type'] = 'string',
            $rule === 'uuid' => $this->setFormat($schema, 'uuid'),
            $rule === 'url' => $this->setFormat($schema, 'uri'),
            $rule === 'email' => $this->setFormat($schema, 'email'),
            $rule === 'date' => $this->setFormat($schema, 'date-time'),
            $rule === 'date_format:Y-m-d' => $this->setFormat($schema, 'date'),
            str_starts_with($rule, 'max:') => $this->applyMaxConstraint($rule, $schema),
            str_starts_with($rule, 'min:') => $this->applyMinConstraint($rule, $schema),
            str_starts_with($rule, 'in:') => $this->applyInConstraint($rule, $schema),
            str_starts_with($rule, 'between:') => $this->applyBetweenConstraint($rule, $schema),
            default => null,
        };
    }

    /**
     * Set format on schema (ensures type is string for format).
     */
    protected function setFormat(array &$schema, string $format): void
    {
        if (! isset($schema['type']) || $schema['type'] === 'string') {
            $schema['type'] = 'string';
            $schema['format'] = $format;
        }
    }

    /**
     * Apply max constraint based on type.
     */
    protected function applyMaxConstraint(string $rule, array &$schema): void
    {
        $value = (int) str_replace('max:', '', $rule);

        if (($schema['type'] ?? 'string') === 'string') {
            $schema['maxLength'] = $value;
        } elseif (in_array($schema['type'] ?? '', ['integer', 'number'])) {
            $schema['maximum'] = $value;
        } elseif (($schema['type'] ?? '') === 'array') {
            $schema['maxItems'] = $value;
        }
    }

    /**
     * Apply min constraint based on type.
     */
    protected function applyMinConstraint(string $rule, array &$schema): void
    {
        $value = (int) str_replace('min:', '', $rule);

        if (($schema['type'] ?? 'string') === 'string') {
            $schema['minLength'] = $value;
        } elseif (in_array($schema['type'] ?? '', ['integer', 'number'])) {
            $schema['minimum'] = $value;
        } elseif (($schema['type'] ?? '') === 'array') {
            $schema['minItems'] = $value;
        }
    }

    /**
     * Apply in constraint as enum.
     */
    protected function applyInConstraint(string $rule, array &$schema): void
    {
        $values = explode(',', str_replace('in:', '', $rule));
        $schema['enum'] = array_map('trim', $values);
    }

    /**
     * Apply between constraint.
     */
    protected function applyBetweenConstraint(string $rule, array &$schema): void
    {
        $parts = explode(',', str_replace('between:', '', $rule));
        if (count($parts) === 2) {
            $min = (int) trim($parts[0]);
            $max = (int) trim($parts[1]);

            if (($schema['type'] ?? 'string') === 'string') {
                $schema['minLength'] = $min;
                $schema['maxLength'] = $max;
            } elseif (in_array($schema['type'] ?? '', ['integer', 'number'])) {
                $schema['minimum'] = $min;
                $schema['maximum'] = $max;
            }
        }
    }

    /**
     * Check if field is required.
     */
    protected function isRequired(array|string $rules): bool
    {
        $rules = is_array($rules) ? $rules : explode('|', $rules);

        foreach ($rules as $rule) {
            if ($rule === 'required') {
                return true;
            }
        }

        return false;
    }

    /**
     * Return an empty schema structure.
     */
    protected function emptySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];
    }
}
