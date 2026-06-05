<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Entity;
use App\Models\Mention;
use App\Models\Source;
use App\Models\Topic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MentionIntelligenceService
{
    /**
     * @param  array{source_id?: int|string|null, source_type?: string|null, sentiment?: string|null, author?: string|null, q?: string|null, date_from?: string|null, date_to?: string|null, brand_id?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, Mention>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['source_id'] ?? null, fn (Builder $query, mixed $sourceId) => $query->where('source_id', (int) $sourceId))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $type) => $query->whereHas('source', fn (Builder $source) => $source->where('type', $type)))
            ->when($filters['sentiment'] ?? null, fn (Builder $query, string $sentiment) => $query->where('sentiment', $sentiment))
            ->when($filters['author'] ?? null, fn (Builder $query, string $author) => $query->where('author', 'like', "%{$author}%"))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(function (Builder $scope) use ($term): void {
                $scope->where('title', 'like', "%{$term}%")
                    ->orWhere('content', 'like', "%{$term}%")
                    ->orWhere('url', 'like', "%{$term}%")
                    ->orWhere('author', 'like', "%{$term}%");
            }))
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
     * @param  array{title?: string|null, content?: string|null, url?: string|null, author?: string|null, source_id?: int|null, brand_id?: int|null, published_at?: mixed, sentiment?: string|null, impact_score?: int|null, metadata?: array<string, mixed>|null, entities?: array<int, array{entity_name: string, entity_type: string}>, topics?: array<int, string>, evidence?: array<int, array<string, mixed>>}  $attributes
     */
    public function create(Account $account, ?Brand $brand, array $attributes): Mention
    {
        $attributes = $this->normalizeAttributes($attributes);
        $mentionBrand = $this->resolveBrand($account, $brand, $attributes['brand_id'] ?? $brand?->id);
        $sourceId = $attributes['source_id'] ?? null;

        if (($attributes['sentiment'] ?? null) !== null && ! in_array($attributes['sentiment'], Mention::SENTIMENTS, true)) {
            throw new InvalidArgumentException("Invalid mention sentiment [{$attributes['sentiment']}].");
        }

        if ($sourceId !== null) {
            $this->assertSourceInTenant($account, $mentionBrand, (int) $sourceId);
        }

        $mentionAttributes = [
            'account_id' => $account->id,
            'brand_id' => $mentionBrand?->id,
            'source_id' => $sourceId,
            'title' => $attributes['title'] ?? null,
            'content' => $attributes['content'] ?? null,
            'url' => $attributes['url'] ?? null,
            'author' => $attributes['author'] ?? null,
            'published_at' => $attributes['published_at'] ?? now(),
            'sentiment' => $attributes['sentiment'] ?? 'neutral',
            'impact_score' => isset($attributes['impact_score']) ? max(0, min(100, (int) $attributes['impact_score'])) : null,
            'metadata' => [
                'capture_mode' => 'manual_foundation',
                'normalized_at' => now()->toDateTimeString(),
                ...($attributes['metadata'] ?? []),
            ],
        ];

        $mention = $this->existingMention($account, $mentionBrand, $mentionAttributes)
            ?->forceFill($mentionAttributes)
            ?? new Mention($mentionAttributes);

        $mention->save();

        foreach ($attributes['entities'] ?? [] as $entity) {
            $mention->entities()->firstOrCreate([
                'entity_name' => $entity['entity_name'],
                'entity_type' => $entity['entity_type'],
            ]);
        }

        $this->linkEntities($mention);
        $this->linkTopics($mention, $attributes['topics'] ?? []);

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

        if ($mention->evidenceItems()->count() === 0) {
            app(EvidenceService::class)->createForSubject($mention, [
                'source_id' => $mention->source_id,
                'evidence_type' => 'mention',
                'title' => $mention->title,
                'url' => $mention->url,
                'snippet' => $mention->content,
                'raw_payload' => $mention->metadata,
                'confidence_score' => $mention->impact_score,
                'captured_at' => $mention->published_at,
            ]);
        }

        app(DomainEventService::class)->recordForSubject('MentionCaptured', $mention, null, [
            'source_id' => $mention->source_id,
            'title' => $mention->title,
            'url' => $mention->url,
            'sentiment' => $mention->sentiment,
            'impact_score' => $mention->impact_score,
        ], $mention->published_at ?? $mention->created_at);

        $this->generateSignals($mention->refresh());

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

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeAttributes(array $attributes): array
    {
        foreach (['title', 'content', 'url', 'author', 'sentiment'] as $key) {
            if (array_key_exists($key, $attributes) && is_string($attributes[$key])) {
                $attributes[$key] = trim(preg_replace('/\s+/', ' ', $attributes[$key]) ?? $attributes[$key]);
            }
        }

        if (($attributes['url'] ?? null) !== null) {
            $attributes['url'] = Str::of($attributes['url'])
                ->before('#')
                ->trim()
                ->toString();
        }

        foreach ($attributes['entities'] ?? [] as $index => $entity) {
            $attributes['entities'][$index]['entity_name'] = trim((string) ($entity['entity_name'] ?? ''));
            $attributes['entities'][$index]['entity_type'] = Str::of((string) ($entity['entity_type'] ?? 'organization'))->snake()->lower()->toString();
        }

        $attributes['entities'] = array_values(array_filter(
            $attributes['entities'] ?? [],
            fn (array $entity): bool => $entity['entity_name'] !== ''
        ));

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function existingMention(Account $account, ?Brand $brand, array $attributes): ?Mention
    {
        if (blank($attributes['url'] ?? null)) {
            return null;
        }

        return Mention::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand?->id)
            ->where('source_id', $attributes['source_id'] ?? null)
            ->where('url', $attributes['url'])
            ->first();
    }

    private function linkEntities(Mention $mention): void
    {
        $mention->loadMissing('entities');
        $text = Str::lower($mention->title.' '.$mention->content);

        $candidates = Entity::query()
            ->forTenant($mention->account, $mention->brand)
            ->with('aliasRecords')
            ->get()
            ->filter(function (Entity $entity) use ($mention, $text): bool {
                $names = collect([$entity->name, ...($entity->aliases ?? []), ...$entity->aliasRecords->pluck('alias')->all()])
                    ->filter()
                    ->map(fn (string $name): string => Str::lower($name));

                return $mention->entities->contains(fn ($mentionEntity): bool => $names->contains(Str::lower($mentionEntity->entity_name)))
                    || $names->contains(fn (string $name): bool => $name !== '' && str_contains($text, $name));
            });

        foreach ($candidates as $entity) {
            $mention->relationships()->firstOrCreate([
                'related_type' => $entity->getMorphClass(),
                'related_id' => $entity->id,
            ]);
        }
    }

    /**
     * @param  array<int, string>  $explicitTopics
     */
    private function linkTopics(Mention $mention, array $explicitTopics): void
    {
        $text = Str::lower($mention->title.' '.$mention->content);
        $explicit = collect($explicitTopics)->filter()->map(fn (string $topic): string => Str::lower(trim($topic)));

        $topics = Topic::query()
            ->where('account_id', $mention->account_id)
            ->where(fn (Builder $query) => $query
                ->whereNull('brand_id')
                ->orWhere('brand_id', $mention->brand_id))
            ->get()
            ->filter(fn (Topic $topic): bool => $explicit->contains(Str::lower($topic->name)) || str_contains($text, Str::lower($topic->name)));

        foreach ($topics as $topic) {
            $mention->topics()->syncWithoutDetaching([
                $topic->id => [
                    'account_id' => $mention->account_id,
                    'brand_id' => $mention->brand_id,
                    'relationship_type' => 'detected',
                    'relevance_score' => 80,
                ],
            ]);
        }
    }

    private function generateSignals(Mention $mention): void
    {
        $mention->loadMissing('account', 'brand', 'source', 'topics', 'entities', 'relationships.related');

        app(\App\Services\SignalManager::class)->record($mention->account, [
            'source' => $mention->source?->provider ?? 'mention',
            'type' => 'mention_captured',
            'category' => $mention->source?->type === 'social' ? 'social' : 'visibility',
            'priority' => $this->priorityForMention($mention),
            'dedupe_key' => 'mention:'.$mention->id.':captured',
            'title' => 'Mention captured: '.($mention->title ?: 'Untitled mention'),
            'summary' => Str::limit((string) ($mention->content ?: $mention->title), 180),
            'impact_score' => $mention->impact_score,
            'confidence_score' => 85,
            'recommended_action' => $mention->sentiment === 'negative' ? 'Review the mention and decide whether follow-up is needed.' : null,
            'payload' => [
                'mention_id' => $mention->id,
                'source_id' => $mention->source_id,
                'sentiment' => $mention->sentiment,
                'topic_ids' => $mention->topics->pluck('id')->all(),
            ],
            'evidence' => [[
                'source_id' => $mention->source_id,
                'evidence_type' => 'mention',
                'title' => $mention->title,
                'url' => $mention->url,
                'snippet' => $mention->content,
                'confidence_score' => $mention->impact_score,
                'captured_at' => $mention->published_at,
            ]],
        ], $mention->brand);

        if ($mention->sentiment === 'negative' && ($mention->impact_score ?? 0) >= 60) {
            app(\App\Services\SignalManager::class)->record($mention->account, [
                'source' => 'mentions',
                'type' => 'sentiment_shift',
                'category' => 'visibility',
                'priority' => ($mention->impact_score ?? 0) >= 80 ? 'critical' : 'high',
                'dedupe_key' => 'mention:'.$mention->id.':sentiment',
                'title' => 'Negative mention needs review',
                'summary' => Str::limit((string) ($mention->content ?: $mention->title), 180),
                'impact_score' => $mention->impact_score,
                'confidence_score' => 80,
                'recommended_action' => 'Review source evidence and prepare a response or mitigation action.',
                'payload' => ['mention_id' => $mention->id, 'sentiment' => $mention->sentiment],
                'evidence' => [$this->signalEvidenceForMention($mention)],
            ], $mention->brand);
        }

        foreach ($mention->topics as $topic) {
            $count = Mention::query()
                ->where('account_id', $mention->account_id)
                ->where('brand_id', $mention->brand_id)
                ->where('published_at', '>=', now()->subDays(7))
                ->whereHas('topics', fn (Builder $query) => $query->whereKey($topic->id))
                ->count();

            if ($count >= 3) {
                app(\App\Services\SignalManager::class)->record($mention->account, [
                    'source' => 'mentions',
                    'type' => 'topic_velocity',
                    'category' => 'social',
                    'priority' => $count >= 8 ? 'high' : 'medium',
                    'dedupe_key' => 'topic:'.$topic->id.':velocity:'.now()->toDateString(),
                    'title' => 'Topic velocity increased: '.$topic->name,
                    'summary' => "{$count} mentions matched this topic in the last 7 days.",
                    'impact_score' => min(100, 40 + ($count * 5)),
                    'confidence_score' => 75,
                    'recommended_action' => 'Review the topic and decide whether it should become a content, visibility or monitoring priority.',
                    'payload' => ['topic_id' => $topic->id, 'mention_count_7d' => $count],
                    'evidence' => [$this->signalEvidenceForMention($mention)],
                ], $mention->brand);
            }
        }

        $hasCompetitor = $mention->entities->contains('entity_type', 'competitor')
            || $mention->relationships->contains(fn ($relationship): bool => $relationship->related instanceof Entity && $relationship->related->entity_type === 'competitor');

        if ($hasCompetitor) {
            app(\App\Services\SignalManager::class)->record($mention->account, [
                'source' => 'mentions',
                'type' => 'competitor_mention',
                'category' => 'competitor',
                'priority' => ($mention->impact_score ?? 0) >= 70 ? 'high' : 'medium',
                'dedupe_key' => 'mention:'.$mention->id.':competitor',
                'title' => 'Competitor context detected in mention',
                'summary' => Str::limit((string) ($mention->content ?: $mention->title), 180),
                'impact_score' => $mention->impact_score,
                'confidence_score' => 75,
                'recommended_action' => 'Review the mention for competitor movement or positioning opportunities.',
                'payload' => ['mention_id' => $mention->id],
                'evidence' => [$this->signalEvidenceForMention($mention)],
            ], $mention->brand);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function signalEvidenceForMention(Mention $mention): array
    {
        return [
            'source_id' => $mention->source_id,
            'evidence_type' => 'mention',
            'title' => $mention->title,
            'url' => $mention->url,
            'snippet' => $mention->content,
            'confidence_score' => $mention->impact_score,
            'captured_at' => $mention->published_at,
        ];
    }

    private function priorityForMention(Mention $mention): string
    {
        $impact = $mention->impact_score ?? 0;

        return match (true) {
            $impact >= 85 => 'critical',
            $impact >= 70 => 'high',
            $impact >= 45 => 'medium',
            default => 'low',
        };
    }
}
