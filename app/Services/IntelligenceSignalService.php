<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Entity;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\Source;
use App\Models\Topic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class IntelligenceSignalService
{
    /**
     * @param  array{status?: string|null, type?: string|null, category?: string|null, priority?: string|null, brand_id?: string|null, source_id?: string|int|null, topic_id?: string|int|null, entity_id?: string|int|null, sentiment?: string|null, date_from?: string|null, date_to?: string|null}  $filters
     * @return LengthAwarePaginator<IntelligenceSignal>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->latest('detected_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    public function recentForTenant(Account $account, ?Brand $brand = null, int $limit = 5): Collection
    {
        return $this->tenantQuery($account, $brand)
            ->open()
            ->latest('detected_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{open: int, critical: int, high: int, unreviewed: int}
     */
    public function statisticsForTenant(Account $account, ?Brand $brand = null): array
    {
        $open = $this->tenantQuery($account, $brand)->open();

        return [
            'open' => (clone $open)->count(),
            'critical' => (clone $open)->where('priority', 'critical')->count(),
            'high' => (clone $open)->where('priority', 'high')->count(),
            'unreviewed' => (clone $open)->where('status', 'new')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Account $account, array $attributes, ?Brand $brand = null): IntelligenceSignal
    {
        $type = $attributes['type'] ?? null;
        $category = $attributes['category'] ?? 'system';
        $priority = $attributes['priority'] ?? 'medium';
        $severity = $attributes['severity'] ?? $priority;
        $status = $attributes['status'] ?? 'new';

        if (! in_array($type, IntelligenceSignal::TYPES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal type [{$type}].");
        }

        if (! in_array($category, IntelligenceSignal::CATEGORIES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal category [{$category}].");
        }

        if (! in_array($priority, IntelligenceSignal::PRIORITIES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal priority [{$priority}].");
        }

        if (! in_array($severity, IntelligenceSignal::SEVERITIES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal severity [{$severity}].");
        }

        if (! in_array($status, IntelligenceSignal::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid intelligence signal status [{$status}].");
        }

        if ($brand && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Signal brand must belong to the signal account.');
        }

        $signalAttributes = $attributes;
        unset($signalAttributes['evidence']);

        $signal = IntelligenceSignal::query()->create([
            ...$signalAttributes,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'category' => $category,
            'priority' => $priority,
            'severity' => $severity,
            'status' => $status,
        ]);

        app(AlertService::class)->triggerForSignal($signal);

        foreach ($attributes['evidence'] ?? [] as $evidence) {
            app(EvidenceService::class)->createForSubject($signal, [
                'source_id' => $evidence['source_id'] ?? null,
                'evidence_type' => $evidence['evidence_type'] ?? 'provider_payload',
                'title' => $evidence['title'] ?? $signal->title,
                'url' => $evidence['url'] ?? null,
                'snippet' => $evidence['snippet'] ?? $signal->summary,
                'raw_payload' => $evidence['raw_payload'] ?? $signal->payload,
                'confidence_score' => $evidence['confidence_score'] ?? $signal->confidence_score,
                'captured_at' => $evidence['captured_at'] ?? $signal->detected_at,
            ]);
        }

        app(RecommendationEngineService::class)->generateForSignal($signal);

        return $signal;
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): IntelligenceSignal
    {
        return $this->tenantQuery($account, $brand)
            ->with(['evidenceItems.subject', 'recommendations.evidenceItems.source'])
            ->whereKey($id)
            ->firstOrFail();
    }

    private function tenantQuery(Account $account, ?Brand $brand): Builder
    {
        return IntelligenceSignal::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with(['brand', 'evidenceItems.source', 'recommendations' => fn ($query) => $query->with('evidenceItems.source')->latest('created_at')]);
    }

    /**
     * @param  array{status?: string|null, type?: string|null, category?: string|null, priority?: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;
        $type = $filters['type'] ?? null;
        $category = $filters['category'] ?? null;
        $priority = $filters['priority'] ?? null;

        if ($status !== null && $status !== '') {
            abort_unless(in_array($status, IntelligenceSignal::STATUSES, true), 404);
            $query->where('status', $status);
        }

        if ($type !== null && $type !== '') {
            abort_unless(in_array($type, IntelligenceSignal::TYPES, true), 404);
            $query->where('type', $type);
        }

        if ($category !== null && $category !== '') {
            abort_unless(in_array($category, IntelligenceSignal::CATEGORIES, true), 404);
            $query->where('category', $category);
        }

        if ($priority !== null && $priority !== '') {
            abort_unless(in_array($priority, IntelligenceSignal::PRIORITIES, true), 404);
            $query->where('priority', $priority);
        }

        if (($filters['brand_id'] ?? null) !== null && $filters['brand_id'] !== '') {
            $filters['brand_id'] === 'account'
                ? $query->whereNull('brand_id')
                : $query->where('brand_id', (int) $filters['brand_id']);
        }

        if (($filters['source_id'] ?? null) !== null && $filters['source_id'] !== '') {
            $source = Source::query()->findOrFail((int) $filters['source_id']);
            $query->where(fn (Builder $scope) => $scope
                ->where('source', $source->provider)
                ->orWhereHas('evidenceItems', fn (Builder $evidence) => $evidence->where('source_id', $source->id)));
        }

        if (($filters['date_from'] ?? null) !== null && $filters['date_from'] !== '') {
            $query->where('detected_at', '>=', \Illuminate\Support\Carbon::parse($filters['date_from'])->startOfDay());
        }

        if (($filters['date_to'] ?? null) !== null && $filters['date_to'] !== '') {
            $query->where('detected_at', '<=', \Illuminate\Support\Carbon::parse($filters['date_to'])->endOfDay());
        }

        $mentionFilters = array_filter([
            'topic_id' => $filters['topic_id'] ?? null,
            'entity_id' => $filters['entity_id'] ?? null,
            'sentiment' => $filters['sentiment'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if ($mentionFilters !== []) {
            $mentionIds = $this->mentionIdsForFilters($query, $mentionFilters);

            $query->where(function (Builder $scope) use ($mentionIds, $filters): void {
                foreach ($mentionIds as $mentionId) {
                    $scope->orWhere('payload->mention_id', $mentionId);
                }

                if (($filters['topic_id'] ?? null) !== null && $filters['topic_id'] !== '') {
                    $scope->orWhere('payload->topic_id', (int) $filters['topic_id']);
                }
            });
        }
    }

    /**
     * @return Collection<int, Brand>
     */
    public function brandsForAccount(Account $account): Collection
    {
        return Brand::query()->where('account_id', $account->id)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Source>
     */
    public function sourcesForTenant(Account $account, ?Brand $brand): Collection
    {
        return Source::query()
            ->where(fn (Builder $scope) => $scope
                ->whereNull('account_id')
                ->orWhere(fn (Builder $accountScope) => $accountScope
                    ->where('account_id', $account->id)
                    ->where(fn (Builder $brandScope) => $brandScope
                        ->whereNull('brand_id')
                        ->when($brand !== null, fn (Builder $query) => $query->orWhere('brand_id', $brand->id)))))
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Topic>
     */
    public function topicsForTenant(Account $account, ?Brand $brand): Collection
    {
        return Topic::query()
            ->where('account_id', $account->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('brand_id')
                ->when($brand !== null, fn (Builder $scope) => $scope->orWhere('brand_id', $brand->id)))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Entity>
     */
    public function entitiesForTenant(Account $account, ?Brand $brand): Collection
    {
        return Entity::query()
            ->forTenant($account, $brand)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{topic_id?: string|int|null, entity_id?: string|int|null, sentiment?: string|null}  $filters
     * @return array<int>
     */
    private function mentionIdsForFilters(Builder $signalQuery, array $filters): array
    {
        $accountId = $signalQuery->getQuery()->wheres[0]['value'] ?? null;

        $query = Mention::query()
            ->when($accountId !== null, fn (Builder $mention) => $mention->where('account_id', $accountId))
            ->when($filters['sentiment'] ?? null, fn (Builder $mention, string $sentiment) => $mention->where('sentiment', $sentiment))
            ->when($filters['topic_id'] ?? null, fn (Builder $mention, mixed $topicId) => $mention->whereHas('topics', fn (Builder $topic) => $topic->whereKey((int) $topicId)))
            ->when($filters['entity_id'] ?? null, fn (Builder $mention, mixed $entityId) => $mention->whereHas('relationships', fn (Builder $relationship) => $relationship
                ->where('related_type', (new Entity())->getMorphClass())
                ->where('related_id', (int) $entityId)));

        return $query->pluck('id')->all();
    }
}
