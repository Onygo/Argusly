<?php

namespace App\Services\Brief;

/**
 * Builds a minimal valid brief structure when user input is limited.
 *
 * This service ensures drafts can be generated even when briefs are created
 * with only title and primary_keyword (e.g., via the "New Content" form).
 */
class BriefDefaultBuilder
{
    /**
     * Build a minimal valid brief structure.
     *
     * @return array{
     *     intent: array{type: string, keys: array<int, string>},
     *     topic: array{title: string, primary_keyword: string},
     *     audience: array{level: string, persona: string},
     *     search_context: array{stage: string},
     *     structure: array{type: string}
     * }
     */
    public function build(string $title, ?string $keyword = null, ?string $audiencePersona = null): array
    {
        $keyword = $keyword ?: $title;
        $audiencePersona = trim((string) ($audiencePersona ?? '')) ?: 'website visitor';

        return [
            'intent' => [
                'type' => 'informational',
                'keys' => [$keyword],
            ],

            'topic' => [
                'title' => $title,
                'primary_keyword' => $keyword,
            ],

            'audience' => [
                'level' => 'general',
                'persona' => $audiencePersona,
            ],

            'search_context' => [
                'stage' => 'awareness',
            ],

            'structure' => [
                'type' => 'blog_article',
            ],
        ];
    }

    /**
     * Build default values for draft meta based on brief context.
     *
     * @return array<string, mixed>
     */
    public function buildDraftMeta(string $title, ?string $keyword = null, ?string $language = null, ?string $audience = null): array
    {
        $keyword = $keyword ?: $title;
        $language = $language ?: 'en';
        $audience = trim((string) ($audience ?? '')) ?: 'website visitor';

        return [
            'language' => $language,
            'intent' => 'informational',
            'intent_keys' => [$keyword],
            'primary_keyword' => $keyword,
            'audience' => $audience,
            'audience_tags' => [],
            'funnel_stage' => 'awareness',
            'search_intent' => 'informational',
            'structure' => [
                'Opening',
                'Main section',
                'Practical examples',
                'Conclusion',
            ],
        ];
    }

    /**
     * Check if a brief has the required fields for draft generation.
     */
    public function isComplete(array $briefData): bool
    {
        $intentKeys = data_get($briefData, 'intent.keys', []);
        if (empty($intentKeys) && empty(data_get($briefData, 'intent_keys', []))) {
            return false;
        }

        if (empty(data_get($briefData, 'audience')) && empty(data_get($briefData, 'audience.persona'))) {
            return false;
        }

        return true;
    }

    /**
     * Merge default values into incomplete brief data.
     *
     * @param  array<string, mixed>  $briefData
     * @return array<string, mixed>
     */
    public function mergeDefaults(array $briefData, string $title, ?string $keyword = null): array
    {
        $defaults = $this->build($title, $keyword);

        // Only fill in missing values, don't overwrite existing data
        $merged = $briefData;

        // Intent
        if (empty(data_get($merged, 'intent.keys')) && empty(data_get($merged, 'intent_keys'))) {
            $merged['intent'] = $defaults['intent'];
            $merged['intent_keys'] = $defaults['intent']['keys'];
        }

        // Audience
        if (empty(data_get($merged, 'audience'))) {
            $merged['audience'] = $defaults['audience']['persona'];
        }

        // Search context / funnel stage
        if (empty(data_get($merged, 'search_context')) && empty(data_get($merged, 'funnel_stage'))) {
            $merged['search_context'] = $defaults['search_context'];
            $merged['funnel_stage'] = $defaults['search_context']['stage'];
        }

        // Structure
        if (empty(data_get($merged, 'structure'))) {
            $merged['structure'] = [
                'Opening',
                'Main section',
                'Practical examples',
                'Conclusion',
            ];
        }

        return $merged;
    }
}
