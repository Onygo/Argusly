<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class SaveUrlBriefSourceRequest extends FormRequest
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
            'content_source_id' => ['required', 'uuid'],
            'destination_mode' => ['nullable', 'in:connected,api_only,hybrid'],
            'site_id' => ['nullable', 'string', 'required_if:destination_mode,connected'],
            'content_destination_id' => ['nullable', 'string'],
            'manual_source_notes' => ['nullable', 'string', 'max:20000'],
            'next_action' => ['nullable', 'in:save,generate_draft,generate_chain_proposal,create_chain,create_selected_chain_items'],
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
            'chain_items' => ['nullable', 'array'],
            'chain_items.*.title' => ['nullable', 'string', 'max:255'],
            'chain_items.*.content_type' => ['nullable', 'string', 'max:80'],
            'chain_items.*.primary_keyword' => ['nullable', 'string', 'max:255'],
            'chain_items.*.secondary_keywords' => ['nullable', 'string', 'max:1000'],
            'chain_items.*.search_intent' => ['nullable', 'string', 'max:255'],
            'chain_items.*.funnel_stage' => ['nullable', 'string', 'max:64'],
            'chain_items.*.target_audience' => ['nullable', 'string', 'max:255'],
            'chain_items.*.angle' => ['nullable', 'string', 'max:1000'],
            'chain_items.*.key_points' => ['nullable', 'string', 'max:2000'],
            'chain_items.*.cta' => ['nullable', 'string', 'max:500'],
            'chain_items.*.suggested_internal_links' => ['nullable', 'string', 'max:1000'],
            'chain_items.*.status' => ['nullable', 'in:proposed,approved,skipped'],
            'chain_items.*.order' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
