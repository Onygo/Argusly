<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class UpsertCompanyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'short_description' => ['nullable', 'string'],
            'long_description' => ['nullable', 'string'],
            'mission' => ['nullable', 'string'],
            'vision' => ['nullable', 'string'],
            'value_proposition' => ['nullable', 'string'],
            'key_services' => ['nullable', 'string'],
            'value_propositions' => ['nullable', 'string'],
            'proof_points' => ['nullable', 'string'],
            'compliance_rules' => ['nullable', 'string'],
            'banned_claims' => ['nullable', 'string'],
            'target_audience' => ['nullable', 'string'],
        ];
    }
}
