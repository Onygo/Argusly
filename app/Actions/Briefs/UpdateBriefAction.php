<?php

namespace App\Actions\Briefs;

use App\Models\Brief;
use App\Support\ContentPersistencePayloadNormalizer;

class UpdateBriefAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(Brief $brief, array $payload): Brief
    {
        $updates = [];

        foreach ([
            'title',
            'status',
            'language',
            'content_type',
            'output_type',
            'intent',
            'primary_keyword',
            'audience',
            'audience_details',
            'target_audience',
            'funnel_stage',
            'search_intent',
            'notes',
            'tone_of_voice',
            'unique_angle',
            'call_to_action',
            'desired_length_min',
            'desired_length_max',
        ] as $key) {
            if (array_key_exists($key, $payload)) {
                $updates[$key] = $payload[$key];
            }
        }

        if (array_key_exists('secondary_keywords', $payload)) {
            $updates['secondary_keywords'] = is_array($payload['secondary_keywords'])
                ? array_values($payload['secondary_keywords'])
                : null;
        }

        if (array_key_exists('key_points', $payload)) {
            $updates['key_points'] = is_array($payload['key_points'])
                ? array_values($payload['key_points'])
                : null;
        }

        if (array_key_exists('content_destination_id', $payload)) {
            $updates['content_destination_id'] = $payload['content_destination_id'];
        }

        $brief->update(ContentPersistencePayloadNormalizer::normalizeBrief($updates));

        return $brief->fresh();
    }
}
