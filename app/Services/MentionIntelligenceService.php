<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Mention;
use App\Models\Source;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MentionIntelligenceService
{
    /**
     * @param  array{source_id?: int|string|null, sentiment?: string|null, date_from?: string|null, date_to?: string|null, brand_id?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, Mention>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['source_id'] ?? null, fn (Builder $query, mixed $sourceId) => $query->where('source_id', (int) $sourceId))
            ->when($filters['sentiment'] ?? null, fn (Builder $query, string $sentiment) => $query->where('sentiment', $sentiment))
            ->when($filters['brand_id'] ?? null, function (Builder $query, mixed $brandId): void {
                $brandId === 'account'
                    ? $query->whereNull('brand_id')
                    : $query->where('brand_id', (int) $brandId);
            })
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('published_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('published_at', '<=', Carbon::parse($date)->endOfDay()))
            ->with(['brand', 'source.connections.integrationConnection.integration', 'entities'])
            ->recent()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{title?: string|null, content?: string|null, url?: string|null, author?: string|null, source_id?: int|null, brand_id?: int|null, published_at?: mixed, sentiment?: string|null, impact_score?: int|null, metadata?: array<string, mixed>|null, entities?: array<int, array{entity_name: string, entity_type: string}>, evidence?: array<int, array<string, mixed>>}  $attributes
     */
    public function create(Account $account, ?Brand $brand, array $attributes): Mention
    {
        $mentionBrand = $this->resolveBrand($account, $brand, $attributes['brand_id'] ?? $brand?->id);
        $sourceId = $attributes['source_id'] ?? null;

        if (($attributes['sentiment'] ?? null) !== null && ! in_array($attributes['sentiment'], Mention::SENTIMENTS, true)) {
            throw new InvalidArgumentException("Invalid mention sentiment [{$attributes['sentiment']}].");
        }

        if ($sourceId !== null) {
            $this->assertSourceInTenant($account, $mentionBrand, (int) $sourceId);
        }

        $mention = Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $mentionBrand?->id,
            'source_id' => $sourceId,
            'title' => $attributes['title'] ?? null,
            'content' => $attributes['content'] ?? null,
            'url' => $attributes['url'] ?? null,
            'author' => $attributes['author'] ?? null,
            'published_at' => $attributes['published_at'] ?? now(),
            'sentiment' => $attributes['sentiment'] ?? null,
            'impact_score' => isset($attributes['impact_score']) ? max(0, min(100, (int) $attributes['impact_score'])) : null,
            'metadata' => $attributes['metadata'] ?? ['capture_mode' => 'manual_foundation'],
        ]);

        foreach ($attributes['entities'] ?? [] as $entity) {
            $mention->entities()->create($entity);
        }

        foreach ($attributes['evidence'] ?? [] as $evidence) {
            app(EvidenceService::class)->createForSubject($mention, [
                'source_id' => $evidence['source_id'] ?? $mention->source_id,
                'evidence_type' => $evidence['evidence_type'] ?? 'mention',
                'title' => $evidence['title'] ?? $mention->title,
                'url' => $evidence['url'] ?? $mention->url,
                'snippet' => $evidence['snippet'] ?? $mention->content,
                'raw_payload' => $evidence['raw_payload'] ?? null,
                'confidence_score' => $evidence['confidence_score'] ?? $mention->impact_score,
                'captured_at' => $evidence['captured_at'] ?? $mention->published_at,
            ]);
        }

        app(DomainEventService::class)->recordForSubject('MentionCaptured', $mention, null, [
            'source_id' => $mention->source_id,
            'title' => $mention->title,
            'url' => $mention->url,
            'sentiment' => $mention->sentiment,
            'impact_score' => $mention->impact_score,
        ], $mention->published_at ?? $mention->created_at);

        return $mention;
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): Mention
    {
        return $this->tenantQuery($account, $brand)
            ->with(['account', 'brand', 'source.connections.integrationConnection.integration', 'evidenceItems.source', 'entities', 'relationships.related', 'topics'])
            ->findOrFail($id);
    }

    /**
     * @return Collection<int, Mention>
     */
    public function recentForTenant(Account $account, ?Brand $brand, int $limit = 5): Collection
    {
        return $this->tenantQuery($account, $brand)
            ->with(['brand', 'source.connections.integrationConnection.integration'])
            ->recent()
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{positive: int, neutral: int, negative: int, mixed: int, unknown: int, total: int}
     */
    public function sentimentOverview(Account $account, ?Brand $brand): array
    {
        $counts = $this->tenantQuery($account, $brand)
            ->selectRaw("coalesce(sentiment, 'unknown') as sentiment_bucket, count(*) as aggregate")
            ->groupBy('sentiment_bucket')
            ->pluck('aggregate', 'sentiment_bucket');

        return [
            'positive' => (int) ($counts['positive'] ?? 0),
            'neutral' => (int) ($counts['neutral'] ?? 0),
            'negative' => (int) ($counts['negative'] ?? 0),
            'mixed' => (int) ($counts['mixed'] ?? 0),
            'unknown' => (int) ($counts['unknown'] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * @return Collection<int, Source>
     */
    public function sourcesForTenant(Account $account, ?Brand $brand): Collection
    {
        return Source::query()
            ->where(function (Builder $scope) use ($account, $brand): void {
                $scope->whereNull('account_id')
                    ->orWhere(function (Builder $accountScope) use ($account, $brand): void {
                        $accountScope->where('account_id', $account->id)
                            ->where(function (Builder $brandScope) use ($brand): void {
                                $brandScope->whereNull('brand_id')
                                    ->when($brand !== null, fn (Builder $query) => $query->orWhere('brand_id', $brand->id));
                            });
                    });
            })
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Brand>
     */
    public function brandsForAccount(Account $account): Collection
    {
        return Brand::query()
            ->where('account_id', $account->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Builder<Mention>
     */
    private function tenantQuery(Account $account, ?Brand $brand): Builder
    {
        return Mention::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            );
    }

    private function resolveBrand(Account $account, ?Brand $currentBrand, mixed $brandId): ?Brand
    {
        if ($brandId === null || $brandId === '' || $brandId === 'account') {
            return null;
        }

        $brand = $currentBrand && $currentBrand->id === (int) $brandId
            ? $currentBrand
            : Brand::query()->where('account_id', $account->id)->findOrFail((int) $brandId);

        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Mention brand must belong to the same account.');
        }

        return $brand;
    }

    private function assertSourceInTenant(Account $account, ?Brand $brand, int $sourceId): void
    {
        $source = Source::query()->find($sourceId);

        if (! $source || ($source->account_id !== null && $source->account_id !== $account->id)) {
            throw new InvalidArgumentException('Mention source must be a configured source in the same account.');
        }

        if ($source->brand_id !== null && $source->brand_id !== $brand?->id) {
            throw new InvalidArgumentException('Mention source must belong to the same brand scope.');
        }
    }
}
