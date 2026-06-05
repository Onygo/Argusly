<?php

namespace App\Http\Requests\Admin;

use App\Enums\AccessOverrideType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'starts_at' => $this->normalizeNullableInput('starts_at'),
            'ends_at' => $this->normalizeNullableInput('ends_at'),
            'reason' => $this->normalizeNullableInput('reason'),
            'notes' => $this->normalizeNullableInput('notes'),
        ]);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(AccessOverrideType::values())],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    private function normalizeNullableInput(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value !== '' ? $value : null;
    }
}
