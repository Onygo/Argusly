<?php

namespace App\Services\Brief;

use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use Illuminate\Support\Facades\Log;

/**
 * Normalizes content and brief data to ensure a valid structure for draft generation.
 *
 * This service handles the "New Content" -> "Generate Draft" flow where users
 * create content with minimal input (just title + optional keyword) and immediately
 * generate a draft without filling in a full editorial brief.
 *
 * The normalizer ensures all required fields have sensible defaults while
 * preserving any user-provided values.
 */
class NormalizeContentBrief
{
    /**
     * Default values for brief fields.
     */
    private const DEFAULTS = [
        'language' => 'en',
        'intent_type' => 'informational',
        'audience' => 'general',
        'audience_persona' => 'website visitor',
        'funnel_stage' => 'awareness',
        'search_intent' => 'informational',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'preferred_length' => 'medium',
        'tone' => 'Professional, clear, structured, confident.',
        'editorial_intentions' => [
            'Answer the reader question directly',
            'Correct the practical misconception',
            'Support claims with evidence or examples',
            'Translate the insight into a next decision',
        ],
    ];

    /**
     * Normalize a draft's meta to ensure all required fields are present.
     *
     * This is the primary method called by GenerateDraftJob before generation.
     *
     * @return array{normalized: bool, fields_added: array<string>, meta: array<string, mixed>}
     */
    public function normalizeDraftMeta(Draft $draft): array
    {
        $draft->loadMissing(['content', 'brief', 'clientSite.workspace']);

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $fieldsAdded = [];
        $originalMeta = $meta;

        // Resolve title and keyword from available sources
        $title = $this->resolveTitle($draft, $meta);
        $keyword = $this->resolvePrimaryKeyword($draft, $meta, $title);
        $language = $this->resolveLanguage($draft, $meta);

        // Ensure required fields exist
        if (empty($meta['language'])) {
            $meta['language'] = $language;
            $fieldsAdded[] = 'language';
        }

        if (empty($meta['primary_keyword'])) {
            $meta['primary_keyword'] = $keyword;
            $fieldsAdded[] = 'primary_keyword';
        }

        // Intent
        $intentKeys = data_get($meta, 'intent_keys', []);
        if (! is_array($intentKeys) || empty($intentKeys)) {
            $meta['intent_keys'] = [$keyword];
            $fieldsAdded[] = 'intent_keys';
        }

        if (empty($meta['intent'])) {
            $meta['intent'] = self::DEFAULTS['intent_type'];
            $fieldsAdded[] = 'intent';
        }

        // Audience
        if (empty($meta['audience'])) {
            $meta['audience'] = $draft->brief?->audience
                ?: $draft->brief?->target_audience
                ?: self::DEFAULTS['audience_persona'];
            $fieldsAdded[] = 'audience';
        }

        if (! isset($meta['audience_tags']) || ! is_array($meta['audience_tags'])) {
            $meta['audience_tags'] = [];
            $fieldsAdded[] = 'audience_tags';
        }

        // Funnel / search context
        if (empty($meta['funnel_stage'])) {
            $meta['funnel_stage'] = $draft->brief?->funnel_stage ?: self::DEFAULTS['funnel_stage'];
            $fieldsAdded[] = 'funnel_stage';
        }

        if (empty($meta['search_intent'])) {
            $meta['search_intent'] = $draft->brief?->search_intent ?: self::DEFAULTS['search_intent'];
            $fieldsAdded[] = 'search_intent';
        }

        // Editorial intentions for minimal briefs; generation uses the Editorial Plan.
        if (empty($meta['editorial_intentions']) || ! is_array($meta['editorial_intentions'])) {
            $meta['editorial_intentions'] = self::DEFAULTS['editorial_intentions'];
            $fieldsAdded[] = 'editorial_intentions';
        }

        // Content type
        if (empty($meta['content_type'])) {
            $meta['content_type'] = $draft->brief?->content_type ?: self::DEFAULTS['content_type'];
            $fieldsAdded[] = 'content_type';
        }

        // Preferred length
        if (empty($meta['preferred_length'])) {
            $meta['preferred_length'] = $draft->content?->preferred_length
                ?: data_get($meta, 'client_refs.preferred_length')
                ?: self::DEFAULTS['preferred_length'];
            $fieldsAdded[] = 'preferred_length';
        }

        // Tone (optional but helpful)
        if (empty($meta['tone'])) {
            $meta['tone'] = $draft->brief?->tone_of_voice ?: '';
            // Note: Don't add default tone here - DraftGenerationService handles brand voice
        }

        // Secondary keywords normalization
        if (! isset($meta['secondary_keywords']) || ! is_array($meta['secondary_keywords'])) {
            $secondary = $draft->brief?->secondary_keywords;
            $meta['secondary_keywords'] = is_array($secondary) ? $secondary : [];
        }

        // Mark normalization
        $normalized = ! empty($fieldsAdded);
        if ($normalized) {
            $meta['_normalized'] = true;
            $meta['_normalized_at'] = now()->toIso8601String();
            $meta['_normalized_fields'] = $fieldsAdded;
        }

        return [
            'normalized' => $normalized,
            'fields_added' => $fieldsAdded,
            'meta' => $meta,
        ];
    }

    /**
     * Validate that a draft has the minimum required data for generation.
     *
     * @return array{valid: bool, missing: array<string>, errors: array<string>}
     */
    public function validateDraftForGeneration(Draft $draft): array
    {
        $draft->loadMissing(['clientSite', 'content']);

        $missing = [];
        $errors = [];

        // Required: client_site_id
        if (empty($draft->client_site_id)) {
            $missing[] = 'client_site_id';
            $errors[] = 'Draft has no client site ID.';
        }

        // Required: title
        $title = $draft->title ?: data_get($draft->meta, 'title', '');
        if (trim($title) === '') {
            $missing[] = 'title';
            $errors[] = 'Draft has no title.';
        }

        // Required: credit_cost (with auto-resolution)
        $creditCost = (int) ($draft->credit_cost ?? 0);
        if ($creditCost <= 0) {
            // Try to resolve from meta
            $creditCost = (int) data_get($draft->meta, 'required_credits', 0);
            if ($creditCost <= 0) {
                // Use default from config
                $creditCost = max(1, (int) config('argusly.ai.drafts.credit_cost', 4));
            }

            // Auto-fix the draft
            $draft->credit_cost = $creditCost;
            $draft->save();
        }

        // Required: output_type (with default)
        if (empty($draft->output_type)) {
            $draft->output_type = 'kb_article';
            $draft->save();
        }

        // Check site has workspace
        if ($draft->clientSite && ! $draft->clientSite->workspace_id) {
            $errors[] = 'Client site has no workspace.';
        }

        return [
            'valid' => empty($missing) && empty($errors),
            'missing' => $missing,
            'errors' => $errors,
        ];
    }

    /**
     * Get diagnostic context for logging.
     *
     * @return array<string, mixed>
     */
    public function getDiagnosticContext(Draft $draft): array
    {
        $draft->loadMissing(['content', 'brief', 'clientSite.workspace.organization']);

        $meta = is_array($draft->meta) ? $draft->meta : [];

        return [
            'draft_id' => (string) $draft->id,
            'draft_status' => (string) ($draft->status ?? ''),
            'content_id' => (string) ($draft->content_id ?? ''),
            'brief_id' => (string) ($draft->brief_id ?? ''),
            'client_site_id' => (string) ($draft->client_site_id ?? ''),
            'workspace_id' => (string) ($draft->clientSite?->workspace_id ?? ''),
            'organization_id' => (string) ($draft->clientSite?->workspace?->organization_id ?? ''),
            'title' => (string) ($draft->title ?? ''),
            'primary_keyword' => (string) data_get($meta, 'primary_keyword', ''),
            'language' => (string) data_get($meta, 'language', ''),
            'output_type' => (string) ($draft->output_type ?? ''),
            'credit_cost' => (int) ($draft->credit_cost ?? 0),
            'credit_status' => (string) ($draft->credit_status ?? ''),
            'has_brief' => $draft->brief !== null,
            'has_content' => $draft->content !== null,
            'has_site' => $draft->clientSite !== null,
            'has_workspace' => $draft->clientSite?->workspace !== null,
            'meta_keys' => array_keys($meta),
            'intent_keys' => data_get($meta, 'intent_keys', []),
            'has_audience' => ! empty(data_get($meta, 'audience')),
            'has_structure' => ! empty(data_get($meta, 'structure')),
            'brief_defaults_applied' => (bool) data_get($meta, 'brief_defaults_applied', false),
        ];
    }

    /**
     * Build a complete brief structure from minimal input.
     *
     * @return array<string, mixed>
     */
    public function buildMinimalBriefStructure(string $title, ?string $keyword = null, ?string $language = null): array
    {
        $keyword = $keyword ?: $title;
        $language = $language ?: self::DEFAULTS['language'];

        return [
            'title' => $title,
            'language' => $language,
            'intent' => [
                'type' => self::DEFAULTS['intent_type'],
                'keys' => [$keyword],
            ],
            'topic' => [
                'title' => $title,
                'primary_keyword' => $keyword,
            ],
            'audience' => [
                'level' => self::DEFAULTS['audience'],
                'persona' => self::DEFAULTS['audience_persona'],
            ],
            'search_context' => [
                'stage' => self::DEFAULTS['funnel_stage'],
                'intent' => self::DEFAULTS['search_intent'],
            ],
            'editorial_plan_seed' => [
                'type' => self::DEFAULTS['output_type'],
                'intentions' => self::DEFAULTS['editorial_intentions'],
            ],
            'content_type' => self::DEFAULTS['content_type'],
            'output_type' => self::DEFAULTS['output_type'],
            'preferred_length' => self::DEFAULTS['preferred_length'],
        ];
    }

    private function resolveTitle(Draft $draft, array $meta): string
    {
        return trim((string) (
            $draft->title
            ?: data_get($meta, 'title')
            ?: $draft->brief?->title
            ?: $draft->content?->title
            ?: 'Untitled'
        ));
    }

    private function resolvePrimaryKeyword(Draft $draft, array $meta, string $fallbackTitle): string
    {
        return trim((string) (
            data_get($meta, 'primary_keyword')
            ?: $draft->brief?->primary_keyword
            ?: $draft->content?->primary_keyword
            ?: $fallbackTitle
        ));
    }

    private function resolveLanguage(Draft $draft, array $meta): string
    {
        $language = trim((string) (
            data_get($meta, 'language')
            ?: $draft->brief?->language
            ?: $draft->content?->language?->value
            ?: $draft->clientSite?->workspace?->defaultContentLanguageCode()
            ?: ''
        ));

        return $language !== '' ? $language : self::DEFAULTS['language'];
    }
}
