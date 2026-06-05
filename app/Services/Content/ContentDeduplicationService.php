<?php

namespace App\Services\Content;

use App\Models\Content;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentDeduplicationService
{
    public const DEFAULT_DUPLICATE_WINDOW_MINUTES = 60;

    /**
     * @param  array<string, mixed>  $scope
     */
    public function fingerprint(array $scope): string
    {
        $normalized = $this->normalizeScope($scope);

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $scope
     */
    public function createOrReuse(array $payload, array $scope): Content
    {
        $workspaceId = trim((string) ($payload['workspace_id'] ?? ''));
        if ($workspaceId === '') {
            throw new \InvalidArgumentException('Content deduplication requires workspace_id.');
        }

        $fingerprint = trim((string) ($payload['dedupe_fingerprint'] ?? ''));
        if ($fingerprint === '') {
            $fingerprint = $this->fingerprint($scope !== [] ? $scope : $this->scopeFromPayload($payload));
        }

        $payload['dedupe_fingerprint'] = $fingerprint;
        $payload['duplicate_checked_at'] = $payload['duplicate_checked_at'] ?? now();

        $lock = Cache::lock($this->lockKey($workspaceId, $fingerprint), 120);

        return $lock->block(10, function () use ($workspaceId, $fingerprint, $payload, $scope): Content {
            $existing = $this->findExisting($workspaceId, $fingerprint);
            if ($existing instanceof Content) {
                return $this->markReused($existing, $scope, $payload);
            }

            try {
                return Content::query()->create($payload);
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                $existing = $this->findExisting($workspaceId, $fingerprint);
                if (! $existing instanceof Content) {
                    throw $exception;
                }

                return $this->markReused($existing, $scope, $payload, true);
            }
        });
    }

    public function lockKey(string $workspaceId, string $fingerprint): string
    {
        return 'content_dedupe:' . $workspaceId . ':' . $fingerprint;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function scopeFromPayload(array $payload): array
    {
        return Arr::only($payload, [
            'workspace_id',
            'client_site_id',
            'automation_id',
            'automation_run_id',
            'source_chain_suggestion_id',
            'series_id',
            'language',
            'type',
            'external_key',
            'primary_keyword',
            'title',
        ]);
    }

    /**
     * @return Collection<int, array{
     *   key:string,
     *   title:string,
     *   language:string,
     *   workspace_id:string,
     *   client_site_id:string,
     *   primary_keyword:?string,
     *   canonical_id:string,
     *   duplicate_ids:array<int,string>,
     *   ids:array<int,string>,
     *   count:int
     * }>
     */
    public function detectDuplicateGroups(
        int $limit = 500,
        int $windowMinutes = self::DEFAULT_DUPLICATE_WINDOW_MINUTES,
        bool $exactTitle = false,
    ): Collection {
        $windowMinutes = max(1, $windowMinutes);

        return Content::query()
            ->whereNull('duplicate_of_content_id')
            ->withCount('publications')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'workspace_id',
                'client_site_id',
                'title',
                'language',
                'primary_keyword',
                'status',
                'publish_status',
                'first_published_at',
                'created_at',
            ])
            ->groupBy(fn (Content $content): string => implode('|', [
                (string) $content->workspace_id,
                (string) ($content->client_site_id ?? ''),
                $this->normalizeMatchValue($content->localeCode()),
                $this->normalizeMatchValue((string) $content->title),
            ]))
            ->flatMap(function (Collection $contents, string $key) use ($windowMinutes, $exactTitle): Collection {
                if ($exactTitle) {
                    return collect([$this->formatDuplicateGroup($contents, $key)]);
                }

                return $this->buildCandidateGroups($contents, $key, $windowMinutes);
            })
            ->filter(fn (array $group): bool => count($group['ids']) > 1)
            ->take($limit)
            ->values();
    }

    /**
     * @param  array{
     *   canonical_id:string,
     *   duplicate_ids:array<int,string>
     * }  $group
     * @return array{canonical_id:string, duplicate_ids:array<int,string>, deleted_count:int}
     */
    public function cleanupDuplicateGroup(array $group, bool $includeFamilies = false): array
    {
        $canonicalId = (string) ($group['canonical_id'] ?? '');
        $duplicateIds = collect($group['duplicate_ids'] ?? [])
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($canonicalId === '' || $duplicateIds === []) {
            return [
                'canonical_id' => $canonicalId,
                'duplicate_ids' => $duplicateIds,
                'deleted_count' => 0,
            ];
        }

        $deleteIds = $includeFamilies
            ? $this->expandDuplicateIdsToFamilies($duplicateIds, $canonicalId)
            : $duplicateIds;

        DB::transaction(function () use ($canonicalId, $deleteIds): void {
            $canonical = Content::query()->findOrFail($canonicalId);

            if (! $canonical->dedupe_fingerprint) {
                $canonical->forceFill([
                    'dedupe_fingerprint' => $this->fingerprint($this->scopeFromPayload($canonical->getAttributes())),
                    'duplicate_checked_at' => now(),
                ])->save();
            } else {
                $canonical->forceFill([
                    'duplicate_checked_at' => now(),
                ])->save();
            }

            Content::query()
                ->whereIn('id', $deleteIds)
                ->get()
                ->each(function (Content $duplicate) use ($canonical): void {
                    $duplicate->forceFill([
                        'duplicate_of_content_id' => (string) $canonical->id,
                        'duplicate_checked_at' => now(),
                    ])->save();

                    $duplicate->delete();
                });
        });

        Log::notice('content.dedupe_cleanup_completed', [
            'canonical_id' => $canonicalId,
            'duplicate_ids' => $duplicateIds,
            'include_families' => $includeFamilies,
            'deleted_count' => count($deleteIds),
        ]);

        return [
            'canonical_id' => $canonicalId,
            'duplicate_ids' => $duplicateIds,
            'deleted_count' => count($deleteIds),
        ];
    }

    /**
     * Find exact or near-title conflicts for a single content item in the same site and locale.
     *
     * @return array<int,array{
     *   content_id:string,
     *   title:string,
     *   similarity:int,
     *   match_type:string,
     *   status:?string,
     *   publish_status:?string
     * }>
     */
    public function titleSimilarityRisks(Content $content, int $limit = 5, int $threshold = 86): array
    {
        $title = trim((string) $content->title);
        $workspaceId = trim((string) $content->workspace_id);

        if ($title === '' || $workspaceId === '' || ! $content->getKey()) {
            return [];
        }

        $normalizedTitle = $this->normalizeTitleForSimilarity($title);
        if ($normalizedTitle === '') {
            return [];
        }

        $locale = $content->localeCode();
        $siteId = $content->client_site_id ? (string) $content->client_site_id : null;
        $type = trim((string) ($content->type ?? ''));

        return Content::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('duplicate_of_content_id')
            ->whereKeyNot($content->getKey())
            ->when($siteId !== null && $siteId !== '', fn ($query) => $query->where('client_site_id', $siteId))
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->get(['id', 'title', 'language', 'status', 'publish_status'])
            ->filter(fn (Content $candidate): bool => $candidate->localeCode() === $locale)
            ->map(function (Content $candidate) use ($normalizedTitle): ?array {
                $candidateTitle = trim((string) $candidate->title);
                $candidateNormalized = $this->normalizeTitleForSimilarity($candidateTitle);

                if ($candidateNormalized === '') {
                    return null;
                }

                $isExact = $candidateNormalized === $normalizedTitle;
                $similarity = $isExact
                    ? 100
                    : $this->titleSimilarityScore($normalizedTitle, $candidateNormalized);

                return [
                    'content_id' => (string) $candidate->id,
                    'title' => $candidateTitle,
                    'similarity' => $similarity,
                    'match_type' => $isExact ? 'exact_title' : 'similar_title',
                    'status' => $candidate->status ? (string) $candidate->status : null,
                    'publish_status' => $candidate->publish_status ? (string) $candidate->publish_status : null,
                ];
            })
            ->filter(fn (?array $risk): bool => is_array($risk) && ((string) $risk['match_type'] === 'exact_title' || (int) $risk['similarity'] >= $threshold))
            ->sortByDesc(fn (array $risk): int => ((string) $risk['match_type'] === 'exact_title' ? 1000 : 0) + (int) $risk['similarity'])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $duplicateIds
     * @return array<int,string>
     */
    private function expandDuplicateIdsToFamilies(array $duplicateIds, string $canonicalId): array
    {
        if ($duplicateIds === []) {
            return [];
        }

        return Content::query()
            ->where(function ($query) use ($duplicateIds): void {
                $query->whereIn('id', $duplicateIds)
                    ->orWhereIn('family_id', $duplicateIds)
                    ->orWhereIn('translation_source_content_id', $duplicateIds);
            })
            ->whereKeyNot($canonicalId)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeTitleForSimilarity(string $title): string
    {
        return trim(preg_replace(
            '/\s+/',
            ' ',
            preg_replace('/[^a-z0-9\s]/', ' ', Str::lower(Str::ascii($title))) ?: ''
        ) ?: '');
    }

    private function titleSimilarityScore(string $left, string $right): int
    {
        similar_text($left, $right, $characterPercent);

        $leftTokens = $this->meaningfulTitleTokens($left);
        $rightTokens = $this->meaningfulTitleTokens($right);
        $tokenPercent = 0;

        if ($leftTokens !== [] && $rightTokens !== []) {
            $intersection = count(array_intersect($leftTokens, $rightTokens));
            $tokenPercent = (int) round(($intersection / max(count($leftTokens), count($rightTokens))) * 100);
        }

        $containsPercent = 0;
        $shorter = mb_strlen($left) <= mb_strlen($right) ? $left : $right;
        $longer = $shorter === $left ? $right : $left;
        if (mb_strlen($shorter) >= 18 && str_contains($longer, $shorter)) {
            $containsPercent = (int) round((mb_strlen($shorter) / max(1, mb_strlen($longer))) * 100);
        }

        return (int) min(100, round(max($characterPercent, $tokenPercent, $containsPercent)));
    }

    /**
     * @return array<int,string>
     */
    private function meaningfulTitleTokens(string $title): array
    {
        $stopWords = [
            'a', 'an', 'and', 'are', 'as', 'de', 'een', 'en', 'for', 'from', 'het',
            'how', 'in', 'is', 'naar', 'of', 'on', 'or', 'the', 'to', 'van', 'voor',
            'wat', 'why', 'with',
        ];

        return collect(explode(' ', $title))
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => mb_strlen($token) > 2 && ! in_array($token, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }

    private function findExisting(string $workspaceId, string $fingerprint): ?Content
    {
        return Content::query()
            ->where('workspace_id', $workspaceId)
            ->where('dedupe_fingerprint', $fingerprint)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $payload
     */
    private function markReused(Content $content, array $scope, array $payload, bool $fromConstraint = false): Content
    {
        $content->forceFill([
            'duplicate_checked_at' => now(),
            'dedupe_was_reused' => true,
            'dedupe_reused_at' => now(),
            'dedupe_reuse_reason' => $fromConstraint ? 'unique_constraint' : 'fingerprint_match',
        ])->save();

        Log::notice('content.dedupe_duplicate_prevented', [
            'content_id' => (string) $content->id,
            'workspace_id' => (string) $content->workspace_id,
            'client_site_id' => (string) ($content->client_site_id ?? $payload['client_site_id'] ?? ''),
            'automation_id' => (string) ($payload['automation_id'] ?? $scope['automation_id'] ?? ''),
            'automation_run_id' => (string) ($payload['automation_run_id'] ?? $scope['automation_run_id'] ?? ''),
            'dedupe_fingerprint' => (string) $content->dedupe_fingerprint,
            'title' => (string) $content->title,
            'from_unique_constraint' => $fromConstraint,
        ]);

        return $content;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, mixed>
     */
    private function normalizeScope(array $scope): array
    {
        $normalized = [];

        foreach ($scope as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[(string) $key] = is_scalar($value)
                ? Str::of((string) $value)->squish()->lower()->toString()
                : $value;
        }

        ksort($normalized);

        return $normalized;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '19'], true);
    }

    /**
     * @return Collection<int, array{
     *   key:string,
     *   title:string,
     *   language:string,
     *   workspace_id:string,
     *   client_site_id:string,
     *   primary_keyword:?string,
     *   canonical_id:string,
     *   duplicate_ids:array<int,string>,
     *   ids:array<int,string>,
     *   count:int
     * }>
     */
    private function buildCandidateGroups(Collection $contents, string $baseKey, int $windowMinutes): Collection
    {
        $groups = collect();
        $current = collect();
        $currentStartAt = null;
        $currentKeyword = null;

        foreach ($contents as $content) {
            $keyword = $this->normalizeMatchValue((string) ($content->primary_keyword ?? ''));
            $createdAt = $content->created_at instanceof CarbonInterface
                ? $content->created_at
                : null;

            if ($current->isEmpty()) {
                $current = collect([$content]);
                $currentStartAt = $createdAt;
                $currentKeyword = $keyword;

                continue;
            }

            $sameKeyword = $keyword !== '' && $currentKeyword !== '' && $keyword === $currentKeyword;
            $withinWindow = $createdAt instanceof CarbonInterface
                && $currentStartAt instanceof CarbonInterface
                && $createdAt->diffInMinutes($currentStartAt) <= $windowMinutes;

            if ($sameKeyword || $withinWindow) {
                $current->push($content);
                $currentKeyword = $currentKeyword !== '' ? $currentKeyword : $keyword;

                continue;
            }

            $groups->push($this->formatDuplicateGroup($current, $baseKey));
            $current = collect([$content]);
            $currentStartAt = $createdAt;
            $currentKeyword = $keyword;
        }

        if ($current->isNotEmpty()) {
            $groups->push($this->formatDuplicateGroup($current, $baseKey));
        }

        return $groups;
    }

    /**
     * @param  Collection<int, Content>  $contents
     * @return array{
     *   key:string,
     *   title:string,
     *   language:string,
     *   workspace_id:string,
     *   client_site_id:string,
     *   primary_keyword:?string,
     *   canonical_id:string,
     *   duplicate_ids:array<int,string>,
     *   ids:array<int,string>,
     *   count:int
     * }
     */
    private function formatDuplicateGroup(Collection $contents, string $baseKey): array
    {
        /** @var Content $canonical */
        $canonical = $this->chooseCanonical($contents);

        $ids = $contents->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $duplicateIds = $contents
            ->reject(fn (Content $content): bool => (string) $content->id === (string) $canonical->id)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return [
            'key' => $baseKey,
            'title' => (string) $canonical->title,
            'language' => $canonical->localeCode(),
            'workspace_id' => (string) $canonical->workspace_id,
            'client_site_id' => (string) ($canonical->client_site_id ?? ''),
            'primary_keyword' => $canonical->primary_keyword,
            'canonical_id' => (string) $canonical->id,
            'duplicate_ids' => $duplicateIds,
            'ids' => $ids,
            'count' => count($ids),
        ];
    }

    /**
     * @param  Collection<int, Content>  $contents
     */
    private function chooseCanonical(Collection $contents): Content
    {
        /** @var Content $canonical */
        $canonical = $contents
            ->sort(function (Content $left, Content $right): int {
                $leftScore = $this->canonicalScore($left);
                $rightScore = $this->canonicalScore($right);

                if ($leftScore !== $rightScore) {
                    return $rightScore <=> $leftScore;
                }

                $leftPublishedAt = $left->first_published_at?->getTimestamp() ?? PHP_INT_MAX;
                $rightPublishedAt = $right->first_published_at?->getTimestamp() ?? PHP_INT_MAX;
                if ($leftPublishedAt !== $rightPublishedAt) {
                    return $leftPublishedAt <=> $rightPublishedAt;
                }

                $leftCreatedAt = $left->created_at?->getTimestamp() ?? PHP_INT_MAX;
                $rightCreatedAt = $right->created_at?->getTimestamp() ?? PHP_INT_MAX;
                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $leftCreatedAt <=> $rightCreatedAt;
                }

                return strcmp((string) $left->id, (string) $right->id);
            })
            ->first();

        return $canonical;
    }

    private function canonicalScore(Content $content): int
    {
        $score = 0;

        if ((string) $content->status === 'published' || (string) ($content->publish_status ?? '') === 'published') {
            $score += 100;
        }

        if ((int) ($content->publications_count ?? 0) > 0) {
            $score += 50;
        }

        if ($content->first_published_at !== null) {
            $score += 25;
        }

        return $score;
    }

    private function normalizeMatchValue(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', ' ')
            ->squish()
            ->toString();
    }
}
