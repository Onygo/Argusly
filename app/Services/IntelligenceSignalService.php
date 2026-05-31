<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntelligenceSignal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class IntelligenceSignalService
{
    /**
     * @param  array{status?: string|null, type?: string|null, category?: string|null, priority?: string|null}  $filters
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
            'status' => $status,
        ]);

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
        return $this->tenantQuery($account, $brand)->whereKey($id)->firstOrFail();
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
    }
}
