<?php

namespace App\Services\LinkIntelligence;

use App\Contracts\LinkIntelligence\EmbeddingService;
use App\Contracts\LinkIntelligence\EntityExtractionService;
use App\Contracts\LinkIntelligence\LinkRelevanceService;
use App\DTO\LinkIntelligence\LinkScore;
use App\Models\ArticleEmbedding;
use App\Models\ArticleEntity;
use App\Models\Draft;
use App\Models\LinkProfile;

class DefaultLinkRelevanceService implements LinkRelevanceService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly EntityExtractionService $entityExtractionService,
    ) {}

    public function scoreCandidate(Draft $source, Draft $target): LinkScore
    {
        $sourceWorkspaceId = $source->clientSite?->workspace_id;
        $profile = $sourceWorkspaceId
            ? LinkProfile::query()->firstOrCreate(['workspace_id' => $sourceWorkspaceId])
            : null;

        $similarityThreshold = (float) ($profile?->min_similarity_threshold ?? 0.70);
        $audienceThreshold = (float) ($profile?->min_audience_overlap_threshold ?? 0.60);

        $sourceEmbedding = $this->resolveEmbedding($source);
        $targetEmbedding = $this->resolveEmbedding($target);
        $similarityScore = $this->cosineSimilarity($sourceEmbedding, $targetEmbedding);

        $entityStats = $this->resolveEntityOverlap($source, $target);
        $intentMatchScore = $this->resolveIntentMatchScore($source, $target);
        $audienceOverlapScore = $this->resolveAudienceOverlapScore($source, $target);

        $intentCompatible = $intentMatchScore >= 0.70;
        $entityEligible =
            $entityStats['primary_count'] >= 2 ||
            ($entityStats['primary_count'] >= 1 && $entityStats['secondary_count'] >= 2);

        // Safety override: allow clearly strong editorial matches even when entity extraction is sparse.
        $highConfidenceSemanticMatch =
            $similarityScore >= max($similarityThreshold + 0.15, 0.90) &&
            $intentMatchScore >= 0.90 &&
            $audienceOverlapScore >= max($audienceThreshold, 0.70);

        $isEligible =
            (
                $similarityScore >= $similarityThreshold &&
                $entityEligible &&
                $intentCompatible &&
                $audienceOverlapScore >= $audienceThreshold
            ) ||
            $highConfidenceSemanticMatch;

        $reasons = [];
        if ($similarityScore < $similarityThreshold) {
            $reasons[] = 'Similarity score below threshold.';
        }
        if (! $entityEligible && ! $highConfidenceSemanticMatch) {
            $reasons[] = 'Insufficient shared entities.';
        }
        if (! $intentCompatible) {
            $reasons[] = 'Intent mismatch.';
        }
        if ($audienceOverlapScore < $audienceThreshold) {
            $reasons[] = 'Audience overlap below threshold.';
        }

        if ($isEligible) {
            $reasons[] = $highConfidenceSemanticMatch
                ? 'Eligible editorial match via high-confidence semantic override.'
                : 'Eligible editorial match.';
        }

        return new LinkScore(
            isEligible: $isEligible,
            similarityScore: round($similarityScore, 2),
            sharedPrimaryCount: $entityStats['primary_count'],
            sharedSecondaryCount: $entityStats['secondary_count'],
            intentMatchScore: round($intentMatchScore, 2),
            audienceOverlapScore: round($audienceOverlapScore, 2),
            sharedEntities: $entityStats['shared_entities'],
            reasons: $reasons,
        );
    }

    /**
     * @return array<int, float>
     */
    private function resolveEmbedding(Draft $article): array
    {
        $record = ArticleEmbedding::query()->where('article_id', $article->id)->first();
        if ($record) {
            return array_map('floatval', $record->embedding_json ?? []);
        }

        return $this->embeddingService->buildEmbeddingForArticle($article)->embedding;
    }

    /**
     * @return array{primary_count:int,secondary_count:int,shared_entities:array<int, string>}
     */
    private function resolveEntityOverlap(Draft $source, Draft $target): array
    {
        $sourceEntities = $this->resolveEntities($source);
        $targetEntities = $this->resolveEntities($target);

        $sourcePrimary = collect($sourceEntities)->where('entity_type', 'primary')->pluck('entity')->map(fn ($v) => strtolower((string) $v));
        $targetPrimary = collect($targetEntities)->where('entity_type', 'primary')->pluck('entity')->map(fn ($v) => strtolower((string) $v));
        $sourceSecondary = collect($sourceEntities)->where('entity_type', 'secondary')->pluck('entity')->map(fn ($v) => strtolower((string) $v));
        $targetSecondary = collect($targetEntities)->where('entity_type', 'secondary')->pluck('entity')->map(fn ($v) => strtolower((string) $v));

        $primaryOverlap = $sourcePrimary->intersect($targetPrimary)->values();
        $secondaryOverlap = $sourceSecondary->intersect($targetSecondary)->values();

        return [
            'primary_count' => $primaryOverlap->count(),
            'secondary_count' => $secondaryOverlap->count(),
            'shared_entities' => $primaryOverlap
                ->merge($secondaryOverlap)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array{entity:string,entity_type:string}>
     */
    private function resolveEntities(Draft $article): array
    {
        $rows = ArticleEntity::query()
            ->where('article_id', $article->id)
            ->get(['entity', 'entity_type'])
            ->map(fn (ArticleEntity $entity): array => [
                'entity' => (string) $entity->entity,
                'entity_type' => (string) $entity->entity_type,
            ])
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        $result = $this->entityExtractionService->extractEntities($article);

        return array_map(static fn (array $entity): array => [
            'entity' => (string) $entity['name'],
            'entity_type' => (string) $entity['type'],
        ], $result->entities);
    }

    private function resolveIntentMatchScore(Draft $source, Draft $target): float
    {
        $sourceIntents = $this->resolveIntentKeys($source);
        $targetIntents = $this->resolveIntentKeys($target);

        if ($sourceIntents === [] || $targetIntents === []) {
            return 0.0;
        }

        $compatible = [
            'informational' => ['educational', 'technical'],
            'educational' => ['informational', 'technical'],
            'technical' => ['informational', 'educational'],
            'commercial' => [],
        ];

        $best = 0.0;

        foreach ($sourceIntents as $sourceIntent) {
            foreach ($targetIntents as $targetIntent) {
                if ($sourceIntent === $targetIntent) {
                    $best = max($best, 1.0);
                    continue;
                }

                if (in_array($targetIntent, $compatible[$sourceIntent] ?? [], true)) {
                    $best = max($best, 0.75);
                }
            }
        }

        return $best;
    }

    private function resolveAudienceOverlapScore(Draft $source, Draft $target): float
    {
        $sourceTags = $this->audienceTags($source);
        $targetTags = $this->audienceTags($target);

        if ($sourceTags === [] || $targetTags === []) {
            return 0.0;
        }

        $sourceSet = collect($sourceTags);
        $targetSet = collect($targetTags);

        $intersection = $sourceSet->intersect($targetSet)->count();
        $union = $sourceSet->merge($targetSet)->unique()->count();

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * @return array<int, string>
     */
    private function audienceTags(Draft $article): array
    {
        $article->loadMissing('brief');

        $keys = ['persona_tags', 'sector_tags', 'seniority_tags', 'audience_tags'];

        $tagList = collect($keys)
            ->flatMap(fn (string $key) => $this->expandTags(data_get($article->meta, $key, [])))
            ->all();

        $legacyAudience = (string) data_get($article->meta, 'audience', '');
        if ($legacyAudience !== '') {
            $tagList = array_merge($tagList, preg_split('/[,;|]/', $legacyAudience) ?: []);
        }

        $briefAudience = trim((string) ($article->brief?->audience ?? ''));
        if ($briefAudience !== '') {
            $tagList = array_merge($tagList, preg_split('/[,;|]/', $briefAudience) ?: []);
        }

        $briefAudienceKeys = (array) data_get($article->brief?->client_refs, 'taxonomy.audience_keys', []);
        $tagList = array_merge($tagList, $this->expandTags($briefAudienceKeys));

        return collect($tagList)
            ->map(fn ($tag) => $this->normalizeAudienceTag((string) $tag))
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

    private function normalizeAudienceTag(string $tag): string
    {
        $tag = strtolower(trim($tag));

        if ($tag === '') {
            return '';
        }

        if (
            str_contains($tag, 'dev') ||
            str_contains($tag, 'developer') ||
            str_contains($tag, 'engineer') ||
            str_contains($tag, 'ontwikkelaar')
        ) {
            return 'developer';
        }

        if (
            str_contains($tag, 'cto') ||
            str_contains($tag, 'tech lead') ||
            str_contains($tag, 'technical lead')
        ) {
            return 'tech_lead';
        }

        if (
            str_contains($tag, 'marketing') ||
            str_contains($tag, 'marketeer') ||
            str_contains($tag, 'marketer')
        ) {
            return 'marketer';
        }

        return match ($tag) {
            'dev', 'developer', 'ontwikkelaar', 'engineer', 'software engineer', 'software developer' => 'developer',
            'cto', 'tech lead', 'technical lead' => 'tech_lead',
            'marketeer', 'marketing', 'marketer' => 'marketer',
            default => $tag,
        };
    }

    private function normalizeIntent(string $intent): string
    {
        $intent = strtolower(trim($intent));
        if ($intent === '') {
            return '';
        }

        if (
            str_contains($intent, 'techn') ||
            str_contains($intent, 'how to') ||
            str_contains($intent, 'developer') ||
            str_contains($intent, 'dev')
        ) {
            return 'technical';
        }

        if (
            str_contains($intent, 'inform') ||
            str_contains($intent, 'informatie') ||
            str_contains($intent, 'info')
        ) {
            return 'informational';
        }

        if (
            str_contains($intent, 'educat') ||
            str_contains($intent, 'tutorial') ||
            str_contains($intent, 'guide') ||
            str_contains($intent, 'uitleg')
        ) {
            return 'educational';
        }

        if (
            str_contains($intent, 'commerc') ||
            str_contains($intent, 'sales') ||
            str_contains($intent, 'pricing') ||
            str_contains($intent, 'koop')
        ) {
            return 'commercial';
        }

        return $intent;
    }

    /**
     * @return array<int, string>
     */
    private function resolveIntentKeys(Draft $article): array
    {
        $intentKeys = collect((array) data_get($article->meta, 'intent_keys', []))
            ->map(fn ($intent) => $this->normalizeIntent((string) $intent))
            ->filter()
            ->unique()
            ->values();

        $primaryIntent = $this->normalizeIntent((string) data_get($article->meta, 'intent', ''));
        if ($primaryIntent !== '') {
            $intentKeys->prepend($primaryIntent);
        }

        return $intentKeys
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $length = min(count($a), count($b));
        if ($length === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $dot / (sqrt($normA) * sqrt($normB))));
    }
}
