<?php

use App\Services\ApiDocs\FormRequestSchemaExtractor;

beforeEach(function () {
    $this->extractor = new FormRequestSchemaExtractor();
});

test('converts string validation rule to schema', function () {
    $rules = ['name' => ['required', 'string', 'max:255']];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['type'])->toBe('object');
    expect($schema['properties']['name']['type'])->toBe('string');
    expect($schema['properties']['name']['maxLength'])->toBe(255);
    expect($schema['required'])->toContain('name');
});

test('converts integer validation rule to schema', function () {
    $rules = ['count' => ['required', 'integer', 'min:1', 'max:100']];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['properties']['count']['type'])->toBe('integer');
    expect($schema['properties']['count']['minimum'])->toBe(1);
    expect($schema['properties']['count']['maximum'])->toBe(100);
});

test('converts boolean validation rule to schema', function () {
    $rules = ['is_active' => ['nullable', 'boolean']];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['properties']['is_active']['type'])->toBe('boolean');
    expect($schema['required'])->not->toContain('is_active');
});

test('converts array validation rule to schema', function () {
    $rules = [
        'tags' => ['required', 'array', 'min:1', 'max:10'],
        'tags.*' => ['string', 'max:50'],
    ];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['properties']['tags']['type'])->toBe('array');
    expect($schema['properties']['tags']['minItems'])->toBe(1);
    expect($schema['properties']['tags']['maxItems'])->toBe(10);
    expect($schema['properties']['tags']['items']['type'])->toBe('string');
});

test('converts uuid format to schema', function () {
    $rules = ['id' => ['required', 'uuid']];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['properties']['id']['type'])->toBe('string');
    expect($schema['properties']['id']['format'])->toBe('uuid');
});

test('converts url format to schema', function () {
    $rules = ['website' => ['required', 'url', 'max:2048']];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['properties']['website']['type'])->toBe('string');
    expect($schema['properties']['website']['format'])->toBe('uri');
    expect($schema['properties']['website']['maxLength'])->toBe(2048);
});

test('converts date format to schema', function () {
    $rules = ['created_at' => ['required', 'date']];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['properties']['created_at']['format'])->toBe('date-time');
});

test('converts in rule to enum', function () {
    $rules = ['status' => ['required', 'in:active,inactive,pending']];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['properties']['status']['enum'])->toBe(['active', 'inactive', 'pending']);
});

test('handles nested array object properties', function () {
    $rules = [
        'events' => ['required', 'array', 'min:1'],
        'events.*.event_type' => ['required', 'string'],
        'events.*.timestamp' => ['required', 'date'],
    ];
    $schema = $this->extractor->convertRulesToSchema($rules);

    expect($schema['properties']['events']['type'])->toBe('array');
    expect($schema['properties']['events']['items']['type'])->toBe('object');
    expect($schema['properties']['events']['items']['properties']['event_type']['type'])->toBe('string');
    expect($schema['properties']['events']['items']['properties']['timestamp']['format'])->toBe('date-time');
    expect($schema['properties']['events']['items']['required'])->toContain('event_type');
    expect($schema['properties']['events']['items']['required'])->toContain('timestamp');
});

test('extracts from real form request class', function () {
    $schema = $this->extractor->extract(\App\Http\Requests\Api\V1\Headless\CreateBriefRequest::class);

    expect($schema['type'])->toBe('object');
    expect($schema['required'])->toContain('title');
    expect($schema['properties']['title']['type'])->toBe('string');
    expect($schema['properties']['title']['maxLength'])->toBe(255);
});

test('returns empty schema for non-existent class', function () {
    $schema = $this->extractor->extract('NonExistentClass');

    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toBe([]);
    expect($schema['required'])->toBe([]);
});
