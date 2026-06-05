<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\CompetitorSnapshot;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\Narrative;
use App\Models\VisibilityAnswerEntity;
use App\Models\VisibilityProviderRun;
use App\Services\Signals\SignalManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CompetitorService
{
    /**
     * @param  array{name: string, website: string, industry?: string|null, status?: string|null}  $attributes
     */
    public function add(Account $account, Brand $brand, array $attributes): Competitor
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        $website = $this->normalizedWebsite($attributes['website']);
        $status = $attributes['status'] ?? 'active';

        if (! in_array($status, Competitor::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid competitor status [{$status}].");
        }

        return Competitor::query()->updateOrCreate(
            [
                'account_id' => $account->id,
                'brand_id' => $brand->id,
                'website' => $website,
            ],
            [
                'name' => $attributes['name'],
                'industry' => $attributes['industry'] ?? null,
                'status' => $status,
            ],
        );
    }

    /**
     * @param  array{name?: string|null, website?: string|null, industry?: string|null, status?: string|null}  $attributes
     */
    public function update(Account $account, Brand $brand, Competitor $competitor, array $attributes): Competitor
    {
        $this->ensureCompetitorBelongsToTenant($account, $brand, $competitor);

        if (array_key_exists('status', $attributes) && $attributes['status'] !== null && ! in_array($attributes['status'], Competitor::STATUSES, true)) {
            throw new InvalidArgumentException("Invalid competitor status [{$attributes['status']}].");
        }

        $competitor->update(array_filter([
            'name' => $attributes['name'] ?? null,
            'website' => isset($attributes['website']) ? $this->normalizedWebsite($attributes['website']) : null,
            'industry' => $attributes['industry'] ?? null,
            'status' => $attributes['status'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        return $competitor->refresh();
    }

    /**
     * @return Collection<int, Competitor>
     */
    public function list(Account $account, Brand $brand): Collection
    {
        return $this->tenantQuery($account, $brand)
            ->with(['latestSnapshot' => fn ($query) => $query->limit(1)])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(Account $account, Brand $brand): array
    {
        $competitors = $this->list($account, $brand)->load(['snapshots' => fn ($query) => $query->latest('captured_at')->limit(12)]);
        $snapshots = $competitors
            ->map(fn (Competitor $competitor) => $competitor->latestSnapshot->first())
            ->filter();
        $visibilityRows = $this->visibilityComparison($account, $brand, $competitors);
        $mentionRows = $this->mentionComparison($account, $brand, $competitors);
        $narrativeRows = $this->narrativeComparison($account, $brand, $competitors);

        return [
            'competitors' => $competitors,
            'leaders' => [
                'visibility' => $this->leader($competitors, 'visibility_score'),
                'mentions' => $this->leader($competitors, 'mention_score'),
                'share_of_voice' => $this->leader($competitors, 'share_of_voice'),
            ],
            'averages' => [
                'visibility_score' => $snapshots->avg('visibility_score'),
                'mention_score' => $snapshots->avg('mention_score'),
                'share_of_voice' => $snapshots->avg('share_of_voice'),
            ],
            'monitoring' => $this->monitoringStatus($competitors),
            'visibilityComparison' => $visibilityRows,
            'mentionComparison' => $mentionRows,
            'trendComparison' => $this->trendComparison($competitors),
            'narrativeComparison' => $narrativeRows,
            'alerts' => $this->alerts($account, $brand),
            'executiveSummaries' => $this->executiveSummaries($competitors, $visibilityRows, $mentionRows, $narrativeRows),
            'tracking' => $this->trackingArchitecture($competitors),
        ];
    }

    /**
     * @return Collection<int, CompetitorSnapshot>
     */
    public function monitor(Account $account, Brand $brand): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return $this->tenantQuery($account, $brand)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (Competitor $competitor): CompetitorSnapshot => $this->captureSnapshot($competitor, $this->derivedSnapshotAttributes($account, $brand, $competitor)));
    }

    /**
     * @param  array{captured_at?: mixed, visibility_score?: int|null, mention_score?: int|null, share_of_voice?: int|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function captureSnapshot(Competitor $competitor, array $attributes = []): CompetitorSnapshot
    {
        foreach (['visibility_score', 'mention_score', 'share_of_voice'] as $score) {
            if (array_key_exists($score, $attributes) && $attributes[$score] !== null) {
                $attributes[$score] = max(0, min(100, (int) $attributes[$score]));
            }
        }

        $snapshot = $competitor->snapshots()->create([
            'captured_at' => $attributes['captured_at'] ?? now(),
            'visibility_score' => $attributes['visibility_score'] ?? null,
            'mention_score' => $attributes['mention_score'] ?? null,
            'share_of_voice' => $attributes['share_of_voice'] ?? null,
            'metadata' => [
                'sources' => [
                    'ai_visibility' => 'planned',
                    'serp' => 'planned',
                    'mentions' => 'planned',
                    'brand_tracking' => 'planned',
                ],
                ...($attributes['metadata'] ?? []),
            ],
        ]);

        $this->recordSignalsForSnapshot($competitor, $snapshot);

        return $snapshot;
    }

    private function tenantQuery(Account $account, Brand $brand): Builder
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Competitor::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id);
    }

    private function ensureBrandBelongsToAccount(Account $account, Brand $brand): void
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Competitor brand must belong to the competitor account.');
        }
    }

    private function ensureCompetitorBelongsToTenant(Account $account, Brand $brand, Competitor $competitor): void
    {
        if ($competitor->account_id !== $account->id || $competitor->brand_id !== $brand->id) {
            throw new InvalidArgumentException('Competitor must belong to the current account and brand.');
        }
    }

    private function normalizedWebsite(string $website): string
    {
        $website = trim($website);
        $website = Str::startsWith($website, ['http://', 'https://']) ? $website : 'https://'.$website;

        return rtrim(Str::lower($website), '/');
    }

    private function leader(Collection $competitors, string $metric): ?Competitor
    {
        return $competitors
            ->filter(fn (Competitor $competitor) => $competitor->latestSnapshot->first()?->{$metric} !== null)
            ->sortByDesc(fn (Competitor $competitor) => $competitor->latestSnapshot->first()?->{$metric})
            ->first();
    }

    /**
     * @return array<int, array{key: string, label: string, status: string}>
     */
    private function trackingArchitecture(Collection $competitors): array
    {
        return [
            ['key' => 'ai_visibility', 'label' => 'AI visibility tracking', 'status' => $competitors->isEmpty() ? 'waiting' : 'active'],
            ['key' => 'mentions', 'label' => 'Mention tracking', 'status' => $competitors->isEmpty() ? 'waiting' : 'active'],
            ['key' => 'narratives', 'label' => 'Narrative tracking', 'status' => $competitors->isEmpty() ? 'waiting' : 'active'],
            ['key' => 'alerts', 'label' => 'Competitor alerts', 'status' => $competitors->isEmpty() ? 'waiting' : 'active'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function derivedSnapshotAttributes(Account $account, Brand $brand, Competitor $competitor): array
    {
        $mentionCount = $this->mentionsForCompetitor($account, $brand, $competitor)->count();
        $visibilityCount = $this->visibilityEntitiesForCompetitor($account, $brand, $competitor)->count();
        $allCompetitorMentionCount = $this->tenantCompetitorMentionCount($account, $brand);
        $shareOfVoice = $allCompetitorMentionCount === 0 ? 0 : (int) round(($mentionCount / max(1, $allCompetitorMentionCount)) * 100);

        return [
            'visibility_score' => min(100, $visibilityCount * 20),
            'mention_score' => min(100, $mentionCount * 15),
            'share_of_voice' => $shareOfVoice,
            'metadata' => [
                'sources' => [
                    'ai_visibility' => 'active',
                    'mentions' => 'active',
                    'narratives' => 'active',
                ],
                'mention_count' => $mentionCount,
                'visibility_entity_count' => $visibilityCount,
                'narrative_count' => $this->narrativesForCompetitor($account, $brand, $competitor)->count(),
                'generated_by' => 'competitor_monitoring',
            ],
        ];
    }

    /**
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function visibilityComparison(Account $account, Brand $brand, Collection $competitors): Collection
    {
        return $competitors->map(function (Competitor $competitor) use ($account, $brand): array {
            $entities = $this->visibilityEntitiesForCompetitor($account, $brand, $competitor);
            $runs = VisibilityProviderRun::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->whereIn('id', $entities->pluck('provider_run_id')->unique())
                ->get();

            return [
                'competitor' => $competitor,
                'mentions' => $entities->count(),
                'providers' => $runs->pluck('provider')->filter()->unique()->values(),
                'avg_position' => $entities->whereNotNull('position')->isEmpty() ? null : (int) round($entities->whereNotNull('position')->avg('position')),
                'positive' => $entities->where('sentiment', 'positive')->count(),
            ];
        })->sortByDesc('mentions')->values();
    }

    /**
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function mentionComparison(Account $account, Brand $brand, Collection $competitors): Collection
    {
        return $competitors->map(function (Competitor $competitor) use ($account, $brand): array {
            $mentions = $this->mentionsForCompetitor($account, $brand, $competitor)->with('source')->get();

            return [
                'competitor' => $competitor,
                'mentions' => $mentions->count(),
                'negative' => $mentions->where('sentiment', 'negative')->count(),
                'sentiment_score' => $this->sentimentScore($mentions),
                'top_sources' => $mentions->pluck('source.name')->filter()->countBy()->sortDesc()->take(3),
            ];
        })->sortByDesc('mentions')->values();
    }

    /**
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function narrativeComparison(Account $account, Brand $brand, Collection $competitors): Collection
    {
        return $competitors->map(function (Competitor $competitor) use ($account, $brand): array {
            $narratives = $this->narrativesForCompetitor($account, $brand, $competitor)->withCount(['gaps', 'observations'])->get();

            return [
                'competitor' => $competitor,
                'narratives' => $narratives->count(),
                'open_gaps' => $narratives->sum('gaps_count'),
                'observations' => $narratives->sum('observations_count'),
                'titles' => $narratives->pluck('title')->take(3),
            ];
        })->sortByDesc('narratives')->values();
    }

    /**
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function trendComparison(Collection $competitors): Collection
    {
        return $competitors->map(fn (Competitor $competitor): array => [
            'competitor' => $competitor,
            'points' => $competitor->snapshots
                ->sortBy('captured_at')
                ->map(fn (CompetitorSnapshot $snapshot): array => [
                    'date' => $snapshot->captured_at?->toDateString(),
                    'visibility_score' => $snapshot->visibility_score,
                    'mention_score' => $snapshot->mention_score,
                    'share_of_voice' => $snapshot->share_of_voice,
                ])
                ->values(),
            'delta' => $this->snapshotDelta($competitor),
        ])->values();
    }

    /**
     * @return Collection<int, IntelligenceSignal>
     */
    private function alerts(Account $account, Brand $brand): Collection
    {
        return IntelligenceSignal::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('category', 'competitor')
            ->open()
            ->latest('detected_at')
            ->limit(8)
            ->get();
    }

    /**
     * @param  Collection<int, Competitor>  $competitors
     * @return array<string, mixed>
     */
    private function monitoringStatus(Collection $competitors): array
    {
        $active = $competitors->where('status', 'active');
        $latest = $competitors
            ->map(fn (Competitor $competitor) => $competitor->latestSnapshot->first()?->captured_at)
            ->filter()
            ->sortDesc()
            ->first();

        return [
            'active_competitors' => $active->count(),
            'last_monitored_at' => $latest,
            'coverage' => $competitors->isEmpty() ? 0 : (int) round(($competitors->filter(fn (Competitor $competitor) => $competitor->latestSnapshot->first() !== null)->count() / $competitors->count()) * 100),
        ];
    }

    /**
     * @param  Collection<int, Competitor>  $competitors
     * @param  Collection<int, array<string, mixed>>  $visibilityRows
     * @param  Collection<int, array<string, mixed>>  $mentionRows
     * @param  Collection<int, array<string, mixed>>  $narrativeRows
     * @return Collection<int, array{title: string, summary: string, priority: string}>
     */
    private function executiveSummaries(Collection $competitors, Collection $visibilityRows, Collection $mentionRows, Collection $narrativeRows): Collection
    {
        if ($competitors->isEmpty()) {
            return collect([[
                'title' => 'Competitor baseline is empty',
                'summary' => 'Add competitors to start monitoring movement, visibility and narrative pressure.',
                'priority' => 'medium',
            ]]);
        }

        $topVisibility = $visibilityRows->first();
        $topMentions = $mentionRows->first();
        $topNarrative = $narrativeRows->first();

        return collect([
            [
                'title' => 'Monitoring coverage is active',
                'summary' => $competitors->where('status', 'active')->count().' active competitors are configured for this brand.',
                'priority' => 'low',
            ],
            [
                'title' => 'AI visibility leader: '.($topVisibility['competitor']->name ?? 'No data'),
                'summary' => ($topVisibility['mentions'] ?? 0).' competitor appearances detected in AI visibility answers.',
                'priority' => ($topVisibility['mentions'] ?? 0) >= 3 ? 'high' : 'medium',
            ],
            [
                'title' => 'Mention pressure: '.($topMentions['competitor']->name ?? 'No data'),
                'summary' => ($topMentions['mentions'] ?? 0).' competitor-context mentions with sentiment score '.($topMentions['sentiment_score'] ?? 0).'.',
                'priority' => ($topMentions['negative'] ?? 0) > 0 ? 'high' : 'medium',
            ],
            [
                'title' => 'Narrative overlap: '.($topNarrative['competitor']->name ?? 'No data'),
                'summary' => ($topNarrative['narratives'] ?? 0).' narratives include competitor context.',
                'priority' => ($topNarrative['open_gaps'] ?? 0) > 0 ? 'high' : 'medium',
            ],
        ])->values();
    }

    private function mentionsForCompetitor(Account $account, Brand $brand, Competitor $competitor): Builder
    {
        return Mention::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where(function (Builder $query) use ($competitor): void {
                $query->whereHas('entities', fn (Builder $entity) => $entity
                    ->where('entity_type', 'competitor')
                    ->where('entity_name', $competitor->name))
                    ->orWhere('title', 'like', "%{$competitor->name}%")
                    ->orWhere('content', 'like', "%{$competitor->name}%");
            });
    }

    /**
     * @return Collection<int, VisibilityAnswerEntity>
     */
    private function visibilityEntitiesForCompetitor(Account $account, Brand $brand, Competitor $competitor): Collection
    {
        return VisibilityAnswerEntity::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('entity_type', 'competitor')
            ->where('entity_name', $competitor->name)
            ->get();
    }

    private function narrativesForCompetitor(Account $account, Brand $brand, Competitor $competitor): Builder
    {
        return Narrative::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where(function (Builder $query) use ($competitor): void {
                $query->whereHas('competitors', fn (Builder $competitors) => $competitors->whereKey($competitor->id))
                    ->orWhere('title', 'like', "%{$competitor->name}%")
                    ->orWhere('description', 'like', "%{$competitor->name}%");
            });
    }

    private function tenantCompetitorMentionCount(Account $account, Brand $brand): int
    {
        return Mention::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where(function (Builder $query): void {
                $query->whereHas('entities', fn (Builder $entity) => $entity->where('entity_type', 'competitor'))
                    ->orWhere('content', 'like', '%competitor%');
            })
            ->count();
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     */
    private function sentimentScore(Collection $mentions): int
    {
        if ($mentions->isEmpty()) {
            return 0;
        }

        return (int) round($mentions->sum(fn (Mention $mention): int => match ($mention->sentiment) {
            'positive' => 100,
            'mixed' => 55,
            'negative' => 0,
            default => 50,
        }) / $mentions->count());
    }

    /**
     * @return array<string, int|null>
     */
    private function snapshotDelta(Competitor $competitor): array
    {
        $points = $competitor->snapshots->sortByDesc('captured_at')->values();
        $latest = $points->get(0);
        $previous = $points->get(1);

        return [
            'visibility_score' => $latest && $previous && $latest->visibility_score !== null && $previous->visibility_score !== null ? $latest->visibility_score - $previous->visibility_score : null,
            'mention_score' => $latest && $previous && $latest->mention_score !== null && $previous->mention_score !== null ? $latest->mention_score - $previous->mention_score : null,
            'share_of_voice' => $latest && $previous && $latest->share_of_voice !== null && $previous->share_of_voice !== null ? $latest->share_of_voice - $previous->share_of_voice : null,
        ];
    }

    private function recordSignalsForSnapshot(Competitor $competitor, CompetitorSnapshot $snapshot): void
    {
        $competitor->loadMissing('account', 'brand');

        if (($snapshot->share_of_voice ?? 0) < 50 && ($snapshot->visibility_score ?? 0) < 70) {
            return;
        }

        app(SignalManager::class)->record($competitor->account, [
            'source' => 'competitor_monitoring',
            'type' => 'competitor_movement',
            'category' => 'competitor',
            'priority' => ($snapshot->share_of_voice ?? 0) >= 70 ? 'high' : 'medium',
            'severity' => ($snapshot->share_of_voice ?? 0) >= 70 ? 'high' : 'medium',
            'dedupe_key' => "competitor:{$competitor->id}:snapshot:".$snapshot->captured_at?->toDateString(),
            'title' => 'Competitor movement detected: '.$competitor->name,
            'summary' => "{$competitor->name} reached {$snapshot->share_of_voice}% share of voice and {$snapshot->visibility_score} visibility score.",
            'impact_score' => max((int) ($snapshot->share_of_voice ?? 0), (int) ($snapshot->visibility_score ?? 0)),
            'confidence_score' => 80,
            'recommended_action' => 'Review competitor visibility, mention pressure and narrative overlap.',
            'payload' => [
                'competitor_id' => $competitor->id,
                'competitor_snapshot_id' => $snapshot->id,
                'share_of_voice' => $snapshot->share_of_voice,
                'visibility_score' => $snapshot->visibility_score,
                'mention_score' => $snapshot->mention_score,
            ],
        ], $competitor->brand);
    }
}
