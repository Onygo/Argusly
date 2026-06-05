<?php

namespace App\Services\LinkIntelligence;

use App\Contracts\LinkIntelligence\EmbeddingService;
use App\Contracts\LinkIntelligence\EntityExtractionService;
use App\Contracts\LinkIntelligence\LinkSuggestionService;
use App\Models\ArticleEmbedding;
use App\Models\ArticleEntity;
use App\Models\Draft;
use App\Services\PlanQuotaService;

class BuildArticleSignalsService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly EntityExtractionService $entityExtractionService,
        private readonly LinkSuggestionService $linkSuggestionService,
        private readonly PlanQuotaService $planQuotaService,
    ) {}

    public function handle(Draft $article): void
    {
        $article->loadMissing('clientSite.workspace');

        if (! $article->clientSite?->workspace_id) {
            return;
        }

        if (! $this->planQuotaService->canRunLlmQuery($article->clientSite->workspace, $article->clientSite)) {
            return;
        }

        $this->planQuotaService->incrementUsage(
            workspace: $article->clientSite->workspace,
            site: $article->clientSite,
            metric: PlanQuotaService::METRIC_LLM_QUERIES_RUN,
            amount: 1,
        );

        $embedding = $this->embeddingService->buildEmbeddingForArticle($article);

        ArticleEmbedding::query()->updateOrCreate(
            ['article_id' => $article->id],
            [
                'workspace_id' => $article->clientSite->workspace_id,
                'client_site_id' => $article->client_site_id,
                'embedding_provider' => $embedding->provider,
                'embedding_model' => $embedding->model,
                'embedding_json' => $embedding->embedding,
            ],
        );

        $entities = $this->entityExtractionService->extractEntities($article);

        ArticleEntity::query()->where('article_id', $article->id)->delete();
        foreach ($entities->entities as $entity) {
            ArticleEntity::query()->create([
                'article_id' => $article->id,
                'entity' => (string) ($entity['name'] ?? ''),
                'entity_type' => (string) ($entity['type'] ?? 'secondary'),
                'confidence' => (float) ($entity['confidence'] ?? 0),
            ]);
        }

        $this->normalizeIntentAndAudienceMetadata($article);

        $this->linkSuggestionService->generateSuggestions($article);
    }

    private function normalizeIntentAndAudienceMetadata(Draft $article): void
    {
        $article->loadMissing('brief');
        $meta = (array) ($article->meta ?? []);

        $intent = strtolower((string) ($meta['intent'] ?? 'informational'));
        $allowedIntents = ['informational', 'educational', 'technical', 'commercial'];
        if (! in_array($intent, $allowedIntents, true)) {
            $intent = 'informational';
        }

        $keys = ['persona_tags', 'sector_tags', 'seniority_tags', 'audience_tags'];
        foreach ($keys as $key) {
            $meta[$key] = $this->normalizeTagList($this->expandTags($meta[$key] ?? []));
        }

        $meta['intent_keys'] = collect((array) ($meta['intent_keys'] ?? []))
            ->map(fn ($intentValue) => strtolower(trim((string) $intentValue)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($intent !== '' && ! in_array($intent, $meta['intent_keys'], true)) {
            array_unshift($meta['intent_keys'], $intent);
            $meta['intent_keys'] = array_values(array_unique($meta['intent_keys']));
        }

        // Backward compatibility for existing metadata that stores a single "audience" string.
        $legacyAudienceRaw = (string) ($meta['audience'] ?? '');
        if ($legacyAudienceRaw !== '') {
            $legacyAudienceTags = $this->normalizeTagList(preg_split('/[,;|]/', $legacyAudienceRaw) ?: []);
            $meta['audience_tags'] = collect((array) ($meta['audience_tags'] ?? []))
                ->merge($legacyAudienceTags)
                ->unique()
                ->values()
                ->all();
        }

        $briefAudienceRaw = trim((string) ($article->brief?->audience ?? ''));
        if ($briefAudienceRaw !== '') {
            $briefAudienceTags = $this->normalizeTagList(preg_split('/[,;|]/', $briefAudienceRaw) ?: []);
            $meta['audience_tags'] = collect((array) ($meta['audience_tags'] ?? []))
                ->merge($briefAudienceTags)
                ->unique()
                ->values()
                ->all();
        }

        $briefAudienceKeys = $this->normalizeTagList(
            $this->expandTags((array) data_get($article->brief?->client_refs, 'taxonomy.audience_keys', []))
        );
        if ($briefAudienceKeys !== []) {
            $meta['audience_tags'] = collect((array) ($meta['audience_tags'] ?? []))
                ->merge($briefAudienceKeys)
                ->unique()
                ->values()
                ->all();
        }

        $meta['intent'] = $intent;

        $article->update(['meta' => $meta]);
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, string>
     */
    private function normalizeTagList(array $tags): array
    {
        return collect($tags)
            ->map(fn ($tag) => strtolower(trim((string) $tag)))
            ->map(function (string $tag): string {
                return match ($tag) {
                    'dev', 'developer', 'ontwikkelaar', 'engineer', 'software engineer', 'software developer' => 'developer',
                    'cto', 'tech lead', 'technical lead' => 'tech_lead',
                    'marketeer', 'marketing', 'marketer' => 'marketer',
                    default => $tag,
                };
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private function expandTags(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];
        $expanded = [];

        foreach ($values as $value) {
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }

            if (preg_match('/[,;|]/', $stringValue)) {
                $parts = preg_split('/[,;|]/', $stringValue) ?: [];
                foreach ($parts as $part) {
                    $expanded[] = (string) $part;
                }
                continue;
            }

            $expanded[] = $stringValue;
        }

        return $expanded;
    }
}
