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
            'phone' => ['nullable', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:160'],
            'company' => ['required', 'string', 'max:190'],
            'company_size_visible' => ['nullable', 'string', 'max:80'],
            'industry' => ['nullable', 'string', 'max:160'],
            'website' => ['nullable', 'string', 'max:500'],
            'message' => ['required', 'string', 'max:5000'],
            'intent' => ['required', 'string', 'in:early_access,demo'],
            'utm_source' => ['nullable', 'string', 'max:160'],
            'utm_medium' => ['nullable', 'string', 'max:160'],
            'utm_campaign' => ['nullable', 'string', 'max:160'],
            'marketing_consent' => ['nullable', 'boolean'],
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
            'phone' => trim((string) $this->input('phone', '')),
            'country' => trim((string) $this->input('country', '')),
            'job_title' => trim((string) $this->input('job_title', '')),
            'company' => trim((string) $this->input('company', '')),
            'company_size_visible' => trim((string) $this->input('company_size_visible', '')),
            'industry' => trim((string) $this->input('industry', '')),
            'website' => trim((string) $this->input('website', '')),
            'message' => trim((string) $this->input('message', '')),
            'utm_source' => trim((string) $this->input('utm_source', '')),
            'utm_medium' => trim((string) $this->input('utm_medium', '')),
            'utm_campaign' => trim((string) $this->input('utm_campaign', '')),
            'marketing_consent' => $this->boolean('marketing_consent'),
            'company_size' => trim((string) $this->input('company_size', '')),
        ]);
    }

    private function normalizeIntent(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['early_access', 'demo'], true) ? $normalized : 'early_access';
    }
}
