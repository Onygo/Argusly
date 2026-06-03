<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Entity;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\Narrative;
use App\Models\NarrativeGap;
use App\Models\NarrativeObservation;
use App\Models\Topic;
use App\Models\VisibilityProviderRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class NarrativeIntelligenceService
{
    /**
     * @param  array{type?: string|null, status?: string|null, importance?: string|null}  $filters
     * @return LengthAwarePaginator<int, Narrative>
     */
    public function paginatedForTenant(Account $account, Brand $brand, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Narrative::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->withCount(['observations', 'gaps', 'topics', 'entities', 'mentions', 'competitors', 'visibilityProviderRuns'])
            ->with(['gaps' => fn ($query) => $query->latest()->limit(3)])
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('narrative_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['importance'] ?? null, fn (Builder $query, string $importance) => $query->where('importance', $importance))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array{narratives: int, active: int, observations: int, open_gaps: int, average_gap_score: int|null}
     */
    public function dashboardStats(Account $account, Brand $brand): array
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        $gapQuery = NarrativeGap::query()->where('account_id', $account->id)->where('brand_id', $brand->id);

        return [
            'narratives' => Narrative::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->count(),
            'active' => Narrative::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->where('status', 'active')->count(),
            'observations' => NarrativeObservation::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->count(),
            'open_gaps' => (clone $gapQuery)->whereIn('status', ['new', 'reviewed'])->count(),
            'average_gap_score' => ($average = (clone $gapQuery)->whereNotNull('gap_score')->avg('gap_score')) !== null ? (int) round($average) : null,
        ];
    }

    /**
     * @return Collection<int, NarrativeGap>
     */
    public function openGaps(Account $account, Brand $brand, int $limit = 8): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return NarrativeGap::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereIn('status', ['new', 'reviewed'])
            ->with('narrative')
            ->orderByDesc('gap_score')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createNarrative(Account $account, Brand $brand, array $attributes): Narrative
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        $narrative = Narrative::query()->create($attributes + [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
        ]);

        $this->syncLinks($account, $brand, $narrative, $attributes);
        $narrative->recordDomainEvent('NarrativeCreated', null, ['title' => $narrative->title]);

        return $narrative;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordObservation(Account $account, Brand $brand, Narrative $narrative, array $attributes): NarrativeObservation
    {
        $this->ensureNarrativeBelongsToTenant($account, $brand, $narrative);

        $observation = NarrativeObservation::query()->create($attributes + [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'narrative_id' => $narrative->id,
            'detected_at' => $attributes['detected_at'] ?? now(),
        ]);

        app(DomainEventService::class)->recordForSubject('NarrativeObservationCaptured', $narrative, null, [
            'observation_id' => $observation->id,
            'sentiment' => $observation->sentiment,
            'confidence_score' => $observation->confidence_score,
        ], $observation->detected_at);

        return $observation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function detectGap(Account $account, Brand $brand, Narrative $narrative, array $attributes): NarrativeGap
    {
        $this->ensureNarrativeBelongsToTenant($account, $brand, $narrative);

        $gap = NarrativeGap::query()->create($attributes + [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'narrative_id' => $narrative->id,
        ]);

        $signal = app(IntelligenceSignalService::class)->create($account, [
            'source' => 'narrative_intelligence',
            'type' => 'narrative_gap_detected',
            'category' => 'narrative',
            'priority' => $this->priorityForGapScore($gap->gap_score),
            'title' => "Narrative gap detected: {$narrative->title}",
            'summary' => "Desired narrative '{$gap->desired_state}' is being detected as '{$gap->detected_state}'.",
            'impact_score' => $gap->gap_score ?? 70,
            'confidence_score' => min(95, max(50, (int) ($gap->gap_score ?? 70))),
            'recommended_action' => 'Close the narrative gap with content, positioning, campaigns and citation improvements.',
            'payload' => [
                'narrative_id' => $narrative->id,
                'narrative_gap_id' => $gap->id,
                'desired_state' => $gap->desired_state,
                'detected_state' => $gap->detected_state,
                'gap_score' => $gap->gap_score,
            ],
        ], $brand);

        $gap->recordDomainEvent('NarrativeGapDetected', null, [
            'signal_id' => $signal->id,
            'narrative_id' => $narrative->id,
            'desired_state' => $gap->desired_state,
            'detected_state' => $gap->detected_state,
            'gap_score' => $gap->gap_score,
        ]);

        return $gap;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncLinks(Account $account, Brand $brand, Narrative $narrative, array $attributes): void
    {
        $this->syncTenantIds($narrative->topics(), Topic::class, $account, $brand, $attributes['topic_ids'] ?? []);
        $this->syncTenantIds($narrative->entities(), Entity::class, $account, $brand, $attributes['entity_ids'] ?? []);
        $this->syncTenantIds($narrative->mentions(), Mention::class, $account, $brand, $attributes['mention_ids'] ?? []);
        $this->syncTenantIds($narrative->competitors(), Competitor::class, $account, $brand, $attributes['competitor_ids'] ?? []);
        $this->syncTenantIds($narrative->visibilityProviderRuns(), VisibilityProviderRun::class, $account, $brand, $attributes['visibility_provider_run_ids'] ?? []);
    }

    /**
     * @param  class-string  $model
     * @param  array<int, int|string>  $ids
     */
    private function syncTenantIds($relation, string $model, Account $account, Brand $brand, array $ids): void
    {
        $ids = collect($ids)->filter()->map(fn (mixed $id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $validIds = $model::query()
            ->whereIn('id', $ids)
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->pluck('id')
            ->all();

        $relation->syncWithoutDetaching($validIds);
    }

    private function priorityForGapScore(?int $gapScore): string
    {
        return match (true) {
            $gapScore !== null && $gapScore >= 85 => 'critical',
            $gapScore !== null && $gapScore >= 65 => 'high',
            $gapScore !== null && $gapScore >= 40 => 'medium',
            default => 'low',
        };
    }

    private function ensureNarrativeBelongsToTenant(Account $account, Brand $brand, Narrative $narrative): void
    {
        if ($narrative->account_id !== $account->id || $narrative->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Narrative must belong to the current account and brand.');
        }
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Narrative brand must belong to the account.');
        }
    }
}
