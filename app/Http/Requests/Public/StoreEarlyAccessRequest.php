<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StoreEarlyAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'work_email' => ['required', 'email:rfc', 'max:190'],
            'company' => ['required', 'string', 'max:190'],
            'website' => ['nullable', 'string', 'max:500'],
            'message' => ['required', 'string', 'max:5000'],
            'intent' => ['required', 'string', 'in:early_access,demo'],
            // Hidden anti-spam field. Legitimate users keep this empty.
            'company_size' => ['nullable', 'max:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'intent' => $this->normalizeIntent($this->input('intent')),
            'full_name' => trim((string) $this->input('full_name', '')),
            'work_email' => strtolower(trim((string) $this->input('work_email', ''))),
            'company' => trim((string) $this->input('company', '')),
            'website' => trim((string) $this->input('website', '')),
            'message' => trim((string) $this->input('message', '')),
            'company_size' => trim((string) $this->input('company_size', '')),
        ]);
    }

    private function normalizeIntent(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['early_access', 'demo'], true) ? $normalized : 'early_access';
    }
}
