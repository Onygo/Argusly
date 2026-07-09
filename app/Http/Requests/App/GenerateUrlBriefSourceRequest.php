<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class GenerateUrlBriefSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content_source_id' => ['nullable', 'uuid', 'required_without:source_url'],
            'source_url' => ['nullable', 'url', 'max:2048', 'required_without:content_source_id'],
            'output_mode' => ['required', 'in:brief_only,brief_keywords,brief_chain,full_chain'],
            'extraction_mode' => ['nullable', 'in:default,alternative'],
            'manual_source_notes' => ['nullable', 'string', 'max:20000'],
            'locale' => ['nullable', 'string', 'max:8'],
            'force_new' => ['nullable', 'boolean'],
            'chain_title' => ['nullable', 'string', 'max:255'],
            'chain_goal' => ['nullable', 'string', 'max:1000'],
            'chain_main_topic' => ['nullable', 'string', 'max:255'],
            'chain_primary_keyword' => ['nullable', 'string', 'max:255'],
            'chain_secondary_keywords' => ['nullable', 'string', 'max:5000'],
            'chain_target_audience' => ['nullable', 'string', 'max:255'],
            'chain_funnel_stage' => ['nullable', 'string', 'max:64'],
            'chain_search_intent' => ['nullable', 'string', 'max:255'],
            'chain_tone_of_voice' => ['nullable', 'string', 'max:255'],
            'chain_unique_angle' => ['nullable', 'string', 'max:1000'],
            'chain_items_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'chain_item_types' => ['nullable', 'array'],
            'chain_item_types.*' => ['string', 'max:80'],
            'chain_language' => ['nullable', 'string', 'max:8'],
            'chain_destination_site' => ['nullable', 'string', 'max:255'],
            'chain_cms_destination' => ['nullable', 'string', 'max:255'],
            'chain_cta' => ['nullable', 'string', 'max:500'],
            'chain_internal_link_targets' => ['nullable', 'string', 'max:5000'],
            'chain_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $sourceUrl = trim((string) $this->input('source_url', ''));

        if ($sourceUrl !== '' && ! preg_match('#^https?://#i', $sourceUrl)) {
            $this->merge([
                'source_url' => 'https://' . ltrim($sourceUrl, '/'),
            ]);
        }
    }
}
