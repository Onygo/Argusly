<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:40'],
            'language' => ['sometimes', 'string', 'max:10'],
            'content_type' => ['sometimes', 'string', 'max:60'],
            'output_type' => ['sometimes', 'string', 'max:60'],
            'intent' => ['sometimes', 'nullable', 'string', 'max:80'],
            'primary_keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secondary_keywords' => ['sometimes', 'nullable', 'array'],
            'secondary_keywords.*' => ['string', 'max:255'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'audience_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_audience' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'funnel_stage' => ['sometimes', 'nullable', 'string', 'max:50'],
            'search_intent' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'tone_of_voice' => ['sometimes', 'nullable', 'string'],
            'unique_angle' => ['sometimes', 'nullable', 'string'],
            'key_points' => ['sometimes', 'nullable', 'array'],
            'key_points.*' => ['string', 'max:500'],
            'call_to_action' => ['sometimes', 'nullable', 'string', 'max:500'],
            'desired_length_min' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:12000'],
            'desired_length_max' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:12000'],
            'content_destination_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
