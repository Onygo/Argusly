<?php

namespace App\Services\DraftComparison;

use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;
use App\Services\DraftGenerationService;

class DraftComparisonPromptSnapshotBuilder
{
    public function __construct(
        private readonly DraftGenerationService $draftGenerationService,
    ) {}

    /**
     * Build an auditable prompt snapshot for one variant generation.
     *
     * The `shared_inputs` block is intentionally provider/model agnostic.
     * `provider_key` and `model_key` are stored separately so we can enforce
     * fairness across variants with a stable shared hash.
     *
     * @return array<string, mixed>
     */
    public function buildForVariant(
        DraftComparison $comparison,
        DraftComparisonVariant $variant,
        Draft $draft,
    ): array {
        $draft->loadMissing(['brief']);
        $brief = $draft->brief;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $payload = $this->draftGenerationService->buildGenerationPayloadForDraft($draft);

        $primaryKeyword = trim((string) ($meta['primary_keyword'] ?? ($brief?->primary_keyword ?? '')));
        $secondaryKeywords = $this->normalizeList($meta['secondary_keywords'] ?? ($brief?->secondary_keywords ?? []));
        $keyPoints = $this->normalizeList($meta['key_points'] ?? ($brief?->key_points ?? []));
        $structure = $this->normalizeList($meta['structure'] ?? []);

        if ($structure === []) {
            $structure = ['Opening', 'Main section', 'Practical examples', 'Conclusion'];
        }

        $seoInstructions = array_filter([
            'primary_keyword' => $primaryKeyword !== '' ? $primaryKeyword : null,
            'secondary_keywords' => $secondaryKeywords !== [] ? $secondaryKeywords : null,
            'meta_title_hint' => trim((string) ($draft->seo_title ?? '')) !== '' ? (string) $draft->seo_title : null,
            'meta_description_hint' => trim((string) ($draft->seo_meta_description ?? '')) !== '' ? (string) $draft->seo_meta_description : null,
            'canonical_url_hint' => trim((string) ($draft->seo_canonical ?? '')) !== '' ? (string) $draft->seo_canonical : null,
            'robots_index' => $draft->robots_index,
            'robots_follow' => $draft->robots_follow,
            'schema_type' => trim((string) ($draft->schema_type ?? '')) !== '' ? (string) $draft->schema_type : null,
        ], static fn (mixed $value): bool => $value !== null);

        $sharedInputs = [
            'brief' => [
                'id' => (string) ($brief?->id ?? $comparison->brief_id ?? ''),
                'title' => (string) ($brief?->title ?? $draft->title ?? ''),
                'language' => (string) ($meta['language'] ?? ($brief?->language ?? 'en')),
                'target_audience' => (string) ($meta['audience'] ?? ($brief?->target_audience ?? $brief?->audience ?? '')),
                'article_type' => (string) ($brief?->content_type ?? $meta['content_type'] ?? 'blog'),
                'output_type' => (string) ($draft->output_type ?? $brief?->output_type ?? 'kb_article'),
                'compare_scope' => (string) data_get($comparison->meta, 'compare_scope', DraftComparisonService::COMPARE_SCOPE_FULL_DRAFT),
            ],
            'voice' => [
                'tone' => (string) ($meta['tone'] ?? ($brief?->tone_of_voice ?? '')),
                'brand_voice_id' => (string) ($meta['brand_voice_id'] ?? ($comparison->brand_voice_id ?? data_get($brief?->client_refs, 'brand_voice_id', ''))),
            ],
            'keywords' => [
                'primary' => $primaryKeyword,
                'secondary' => $secondaryKeywords,
            ],
            'content_goals' => array_filter([
                'funnel_stage' => (string) ($meta['funnel_stage'] ?? ($brief?->funnel_stage ?? '')),
                'search_intent' => (string) ($meta['search_intent'] ?? ($brief?->search_intent ?? '')),
                'unique_angle' => (string) ($meta['unique_angle'] ?? ($brief?->unique_angle ?? '')),
                'key_points' => $keyPoints,
                'call_to_action' => (string) ($meta['call_to_action'] ?? ($brief?->call_to_action ?? '')),
                'notes' => (string) ($meta['notes'] ?? ($brief?->notes ?? '')),
            ], static fn (mixed $value): bool => $value !== '' && $value !== []),
            'structure_instructions' => $structure,
            'seo_instructions' => $seoInstructions,
            'shared_instruction_set' => [
                'system_prompt' => (string) ($payload['system'] ?? ''),
                'user_prompt' => (string) ($payload['user'] ?? ''),
                'requested_max_output_tokens' => max(0, (int) ($meta['requested_max_output_tokens'] ?? 0)),
                'generation_type' => (string) ($meta['generation_type'] ?? 'article'),
            ],
        ];

        $normalizedSharedInputs = $this->normalizeForHash($sharedInputs);

        return [
            'schema_version' => 1,
            'captured_at' => now()->toIso8601String(),
            'comparison_id' => (string) $comparison->id,
            'variant_id' => (string) $variant->id,
            'provider_key' => (string) $variant->provider_key,
            'model_key' => (string) $variant->model_key,
            'workspace_id' => (string) ($payload['workspace_id'] ?? ''),
            'shared_inputs' => $normalizedSharedInputs,
            'shared_inputs_hash' => hash('sha256', json_encode($normalizedSharedInputs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'generation_payload' => [
                'provider' => (string) ($payload['provider'] ?? ''),
                'model' => (string) ($payload['model'] ?? ''),
                'system' => (string) ($payload['system'] ?? ''),
                'user' => (string) ($payload['user'] ?? ''),
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
        }

        $string = trim((string) $value);
        if ($string === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\n,]/', $string) ?: []), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }
}
