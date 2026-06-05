<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBrandVoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'default_language' => ['required', 'string', 'max:10'],
            'default_tone' => ['nullable', 'string', 'max:255'],
            'tone_of_voice' => ['nullable', 'string'],
            'writing_style' => ['nullable', 'string'],
            'style_guide' => ['nullable', 'string'],
            'do_rules' => ['nullable', 'string'],
            'dont_rules' => ['nullable', 'string'],
            'example_paragraph' => ['nullable', 'string'],
            'preferred_terminology' => ['nullable', 'string'],
            'disallowed_terminology' => ['nullable', 'string'],
            'formatting_rules' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
            'ai_provider_override' => ['nullable', Rule::in(array_keys((array) config('llm.providers', [])))],
            'ai_model_override' => ['nullable', 'string', 'max:120', 'regex:/^$|^[A-Za-z0-9._:-]+$/'],
        ];
    }
}
