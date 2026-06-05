<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCompanyIntelligenceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_key' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9][a-z0-9_-]*$/i'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_description' => ['nullable', 'string', 'max:10000'],
            'market_category' => ['nullable', 'string', 'max:255'],
            'positioning' => ['nullable', 'string', 'max:10000'],
            'uvp' => ['nullable', 'string', 'max:5000'],
            'pricing_model' => ['nullable', 'string', 'max:255'],
            'tone_of_voice' => ['nullable', 'string', 'max:5000'],
            'source_type' => ['nullable', 'string', 'max:64'],
            'status' => ['required', Rule::in(['active', 'archived'])],
            'is_default' => ['nullable', 'boolean'],
            'brand_voice_id' => ['nullable', 'string', 'exists:brand_voices,id'],
            ...$this->listRules([
                'products_services',
                'regions',
                'locales',
                'icps',
                'personas',
                'buyer_roles',
                'pain_points',
                'objections',
                'buying_triggers',
                'funnel_stages',
                'banned_phrases',
                'messaging_rules',
                'brand_differentiators',
                'proof_points',
                'primary_topics',
                'authority_areas',
                'target_entities',
                'strategic_keywords',
                'query_intents',
                'direct_competitors',
                'indirect_competitors',
                'aspirational_competitors',
            ]),
        ];
    }

    /**
     * @param array<int,string> $fields
     * @return array<string,array<int,string>>
     */
    private function listRules(array $fields): array
    {
        return collect($fields)
            ->mapWithKeys(fn (string $field): array => [$field => ['nullable', 'string', 'max:12000']])
            ->all();
    }
}
