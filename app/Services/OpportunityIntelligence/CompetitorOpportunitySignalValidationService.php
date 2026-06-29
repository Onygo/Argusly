<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\ClientSite;
use App\Models\CompetitorContentOpportunity;
use App\Models\OpportunitySignal;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class CompetitorOpportunitySignalValidationService
{
    /**
     * @param  array{workspace?: string|null, site?: string|null, source_id?: string|null, limit?: int|null}  $filters
     * @return array{summary: array<string,int>, rows: array<int,array<string,mixed>>, issues: array<int,array<string,string>>}
     */
    public function inspect(array $filters = []): array
    {
        $signals = $this->query($filters)
            ->limit(max(1, (int) ($filters['limit'] ?? 100)))
            ->get();

        $rows = $signals
            ->map(fn (OpportunitySignal $signal): array => $this->row($signal))
            ->values();

        return [
            'summary' => [
                'signals' => $rows->count(),
                'eligible' => $rows->where('eligible', true)->count(),
                'linked' => $rows->where('linked', true)->count(),
                'unclustered' => $rows->where('eligible', true)->where('linked', false)->count(),
                'incomplete' => $rows->where('eligible', false)->count(),
                'duplicates' => $rows->where('duplicate', true)->count(),
                'stale' => $rows->where('stale', true)->count(),
            ],
            'rows' => $rows->all(),
            'issues' => $rows
                ->filter(fn (array $row): bool => $row['issues'] !== [])
                ->flatMap(fn (array $row): array => collect($row['issues'])
                    ->map(fn (string $issue): array => [
                        'signal_id' => (string) $row['signal_id'],
                        'source_id' => (string) ($row['source_id'] ?: 'unknown'),
                        'issue' => $issue,
                    ])
                    ->all())
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function issues(OpportunitySignal $signal): array
    {
        $rawSource = (string) $signal->getRawOriginal('source');
        $rawCategory = (string) $signal->getRawOriginal('category');
        $sourceModel = $this->sourceModel($signal);
        $sourceId = $this->sourceId($signal);
        $issues = [];

        if (! $signal->workspace_id || ! Workspace::query()->whereKey($signal->workspace_id)->exists()) {
            $issues[] = 'workspace_missing';
        }

        if (! $signal->client_site_id || ! ClientSite::query()->whereKey($signal->client_site_id)->exists()) {
            $issues[] = 'site_missing';
        }

        if ($sourceModel !== CompetitorContentOpportunity::class || blank($sourceId)) {
            $issues[] = 'source_record_missing';
        } elseif (! CompetitorContentOpportunity::query()->whereKey($sourceId)->exists()) {
            $issues[] = 'source_record_stale';
        }

        if (blank($signal->dedupe_hash)) {
            $issues[] = 'dedupe_hash_missing';
        }

        if (blank($signal->topic) && blank(data_get($signal->evidence, '0.title'))) {
            $issues[] = 'topic_or_title_missing';
        }

        if (
            blank($signal->entity)
            && blank(data_get($signal->metadata, 'competitor.name'))
            && blank(data_get($signal->metadata, 'competitor.domain'))
            && blank(data_get($signal->evidence, '0.competitor.name'))
            && blank(data_get($signal->evidence, '0.competitor.domain'))
        ) {
            $issues[] = 'competitor_context_missing';
        }

        if (! is_array($signal->evidence) || $signal->evidence === []) {
            $issues[] = 'evidence_missing';
        }

        if (! in_array($rawCategory, OpportunityCategory::values(), true)) {
            $issues[] = 'category_invalid';
        }

        if ($rawSource !== OpportunitySignalSource::COMPETITOR_INTELLIGENCE->value) {
            $issues[] = 'source_not_competitor_intelligence';
        }

        return $issues;
    }

    /**
     * @param  array{workspace?: string|null, site?: string|null, source_id?: string|null}  $filters
     * @return Builder<OpportunitySignal>
     */
    private function query(array $filters): Builder
    {
        return OpportunitySignal::query()
            ->withCount('opportunities')
            ->where('source', OpportunitySignalSource::COMPETITOR_INTELLIGENCE->value)
            ->where(function (Builder $query): void {
                $query->where('metadata->source_type', CompetitorContentOpportunitySignalPromotionService::SOURCE_TYPE)
                    ->orWhere('metadata->competitor_content_opportunity_id', '!=', null);
            })
            ->when($filters['workspace'] ?? null, fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($filters['site'] ?? null, fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($filters['source_id'] ?? null, function (Builder $query, string $sourceId): Builder {
                return $query->where(function (Builder $query) use ($sourceId): void {
                    $query->where('metadata->source_id', $sourceId)
                        ->orWhere('metadata->competitor_content_opportunity_id', $sourceId);
                });
            })
            ->orderByDesc('created_at')
            ->orderBy('id');
    }

    /**
     * @return array<string,mixed>
     */
    private function row(OpportunitySignal $signal): array
    {
        $issues = $this->issues($signal);
        $duplicate = $this->isDuplicate($signal);
        $stale = in_array('source_record_stale', $issues, true);

        return [
            'signal_id' => (string) $signal->id,
            'workspace_id' => (string) $signal->workspace_id,
            'site_id' => (string) $signal->client_site_id,
            'source_id' => $this->sourceId($signal),
            'source_model' => $this->sourceModel($signal),
            'topic' => (string) ($signal->topic ?: data_get($signal->evidence, '0.title', '')),
            'entity' => (string) ($signal->entity ?: data_get($signal->metadata, 'competitor.name', '')),
            'promoted_at' => optional($signal->created_at)->toDateTimeString(),
            'linked' => ((int) ($signal->opportunities_count ?? 0)) > 0,
            'opportunity_count' => (int) ($signal->opportunities_count ?? 0),
            'duplicate' => $duplicate,
            'stale' => $stale,
            'eligible' => $issues === [],
            'issues' => $duplicate ? array_values(array_unique([...$issues, 'duplicate_signal'])) : $issues,
        ];
    }

    private function sourceId(OpportunitySignal $signal): ?string
    {
        $sourceId = data_get($signal->metadata, 'source_id')
            ?: data_get($signal->metadata, 'competitor_content_opportunity_id')
            ?: data_get($signal->evidence, '0.source_id');

        return filled($sourceId) ? (string) $sourceId : null;
    }

    private function sourceModel(OpportunitySignal $signal): ?string
    {
        $sourceModel = data_get($signal->metadata, 'source_model')
            ?: data_get($signal->evidence, '0.source_model');

        return filled($sourceModel) ? (string) $sourceModel : null;
    }

    private function isDuplicate(OpportunitySignal $signal): bool
    {
        $sourceId = $this->sourceId($signal);

        $dedupeMatches = blank($signal->dedupe_hash)
            ? 0
            : OpportunitySignal::query()
                ->where('workspace_id', $signal->workspace_id)
                ->where('dedupe_hash', $signal->dedupe_hash)
                ->whereKeyNot($signal->id)
                ->count();

        $sourceMatches = blank($sourceId)
            ? 0
            : OpportunitySignal::query()
                ->where('source', OpportunitySignalSource::COMPETITOR_INTELLIGENCE->value)
                ->where('metadata->source_id', $sourceId)
                ->whereKeyNot($signal->id)
                ->count();

        return $dedupeMatches > 0 || $sourceMatches > 0;
    }
}
