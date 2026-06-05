<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'subject' => ['nullable', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
            'topic' => ['nullable', 'string', 'max:120'],
            'source_page' => ['nullable', 'string', 'max:190'],
            'cta_label' => ['nullable', 'string', 'max:190'],
            'url' => ['nullable', 'string', 'max:500'],
            'recaptcha_token' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'recaptcha_token.required' => (string) __('public.page.contact_form.recaptcha_required'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'email' => strtolower(trim((string) $this->input('email', ''))),
            'company' => trim((string) $this->input('company', '')),
            'subject' => trim((string) $this->input('subject', '')),
            'message' => trim((string) $this->input('message', '')),
            'topic' => trim((string) $this->input('topic', '')),
            'source_page' => trim((string) $this->input('source_page', '')),
            'cta_label' => trim((string) $this->input('cta_label', '')),
            'url' => trim((string) $this->input('url', '')),
            'recaptcha_token' => trim((string) $this->input('recaptcha_token', '')),
        ]);
    }
}
