<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;

class CreateBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:10'],
            'content_type' => ['nullable', 'string', 'max:60'],
            'output_type' => ['nullable', 'string', 'max:60'],
            'intent' => ['nullable', 'string', 'max:80'],
            'primary_keyword' => ['nullable', 'string', 'max:255'],
            'secondary_keywords' => ['nullable', 'array'],
            'secondary_keywords.*' => ['string', 'max:255'],
            'audience' => ['nullable', 'string', 'max:2000'],
            'audience_details' => ['nullable', 'string', 'max:5000'],
            'target_audience' => ['nullable', 'string', 'max:5000'],
            'funnel_stage' => ['nullable', 'string', 'max:50'],
            'search_intent' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'tone_of_voice' => ['nullable', 'string'],
            'unique_angle' => ['nullable', 'string'],
            'key_points' => ['nullable', 'array'],
            'key_points.*' => ['string', 'max:500'],
            'call_to_action' => ['nullable', 'string', 'max:500'],
            'desired_length_min' => ['nullable', 'integer', 'min:100', 'max:12000'],
            'desired_length_max' => ['nullable', 'integer', 'min:100', 'max:12000'],
            'content_destination_id' => ['nullable', 'uuid'],
            'generate_draft' => ['nullable', 'boolean'],
            'requested_max_output_tokens' => ['nullable', 'integer', 'min:128', 'max:64000'],
        ];
    }
}
