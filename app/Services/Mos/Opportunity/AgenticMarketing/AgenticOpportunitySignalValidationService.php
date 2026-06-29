<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\OpportunitySignal;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AgenticOpportunitySignalValidationService
{
    public const SOURCE_TYPE = 'agentic_marketing_detector_output';

    /**
     * @param  array{workspace?: string|null, objective?: string|null, site?: string|null, source_id?: string|null, detector?: string|null, limit?: int|null}  $filters
     * @return array{summary: array<string,mixed>, rows: array<int,array<string,mixed>>, issues: array<int,array<string,string>>, detector_breakdown: array<string,int>, category_breakdown: array<string,int>}
     */
    public function inspect(array $filters = []): array
    {
        $signals = $this->query($filters)
            ->limit(max(1, (int) ($filters['limit'] ?? 100)))
            ->get();

        $rows = $signals
            ->map(fn (OpportunitySignal $signal): array => $this->row($signal))
            ->values();

        $issues = $rows
            ->filter(fn (array $row): bool => $row['blocked_reasons'] !== [])
            ->flatMap(fn (array $row): array => collect($row['blocked_reasons'])
                ->map(fn (string $issue): array => [
                    'signal_id' => (string) $row['signal_id'],
                    'source_id' => (string) ($row['legacy_agentic_opportunity_id'] ?: 'unknown'),
                    'issue' => $issue,
                ])
                ->all())
            ->values()
            ->all();

        return [
            'summary' => [
                'total_promoted_agentic_signals' => $rows->count(),
                'eligible' => $rows->where('eligible_for_opportunity_intelligence', true)->count(),
                'linked' => $rows->where('linked_to_canonical_opportunity', true)->count(),
                'unlinked_eligible' => $rows
                    ->where('eligible_for_opportunity_intelligence', true)
                    ->where('linked_to_canonical_opportunity', false)
                    ->count(),
                'incomplete' => $rows->where('incomplete', true)->count(),
                'duplicate_signal_risk' => $rows->where('duplicate_signal_risk', true)->count(),
                'stale_source' => $rows->where('stale_source_risk', true)->count(),
                'sample_blocked_reasons' => collect($issues)
                    ->pluck('issue')
                    ->unique()
                    ->take(8)
                    ->values()
                    ->all(),
            ],
            'rows' => $rows->all(),
            'issues' => $issues,
            'detector_breakdown' => $this->breakdown($rows, 'detector_key'),
            'category_breakdown' => $this->breakdown($rows, 'category'),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function blockedReasons(OpportunitySignal $signal): array
    {
        $rawSource = (string) $signal->getRawOriginal('source');
        $rawCategory = (string) $signal->getRawOriginal('category');
        $sourceId = $this->sourceId($signal);
        $sourceModel = $this->sourceModel($signal);
        $objectiveId = $this->objectiveId($signal);
        $reasons = [];

        if (! $signal->workspace_id || ! Workspace::query()->whereKey($signal->workspace_id)->exists()) {
            $reasons[] = 'workspace_missing';
        }

        if ($signal->client_site_id && ! ClientSite::query()->whereKey($signal->client_site_id)->exists()) {
            $reasons[] = 'client_site_stale';
        }

        if (blank($objectiveId) || ! AgenticMarketingObjective::query()->whereKey($objectiveId)->exists()) {
            $reasons[] = 'objective_missing';
        }

        if ($sourceModel !== AgenticMarketingOpportunity::class || blank($sourceId)) {
            $reasons[] = 'source_record_missing';
        } elseif (! AgenticMarketingOpportunity::query()->whereKey($sourceId)->exists()) {
            $reasons[] = 'source_record_stale';
        }

        if (blank($this->detectorKey($signal))) {
            $reasons[] = 'detector_key_missing';
        }

        if (blank($this->agenticType($signal))) {
            $reasons[] = 'agentic_type_missing';
        }

        if (blank($signal->dedupe_hash)) {
            $reasons[] = 'dedupe_hash_missing';
        }

        if (blank($signal->topic) && blank(data_get($signal->evidence, 'title'))) {
            $reasons[] = 'topic_or_title_missing';
        }

        if (! $this->evidenceComplete($signal)) {
            $reasons[] = 'evidence_incomplete';
        }

        if (! $this->metadataComplete($signal)) {
            $reasons[] = 'metadata_incomplete';
        }

        if (! in_array($rawSource, OpportunitySignalSource::values(), true)) {
            $reasons[] = 'source_invalid';
        }

        if (! in_array($rawCategory, OpportunityCategory::values(), true)) {
            $reasons[] = 'category_invalid';
        }

        if ($this->duplicateSignalRisk($signal)) {
            $reasons[] = 'duplicate_signal_risk';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array{workspace?: string|null, objective?: string|null, site?: string|null, source_id?: string|null, detector?: string|null}  $filters
     * @return Builder<OpportunitySignal>
     */
    private function query(array $filters): Builder
    {
        return OpportunitySignal::query()
            ->with(['opportunities:id'])
            ->withCount('opportunities')
            ->where(function (Builder $query): void {
                $query->where('metadata->source_type', self::SOURCE_TYPE)
                    ->orWhere('metadata->source_model', AgenticMarketingOpportunity::class)
                    ->orWhere('metadata->promotion->version', 'agentic-opportunity-signal-promotion:v1')
                    ->orWhere('metadata->legacy_agentic_marketing_opportunity_id', '!=', null)
                    ->orWhere('metadata->agentic_marketing_opportunity_id', '!=', null);
            })
            ->when($filters['workspace'] ?? null, fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($filters['objective'] ?? null, function (Builder $query, string $objective): Builder {
                return $query->where(function (Builder $query) use ($objective): void {
                    $query->where('metadata->objective_id', $objective)
                        ->orWhere('metadata->promotion->objective_id', $objective)
                        ->orWhere('evidence->legacy_agentic_marketing_opportunity->objective_id', $objective);
                });
            })
            ->when($filters['site'] ?? null, fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($filters['source_id'] ?? null, function (Builder $query, string $sourceId): Builder {
                return $query->where(function (Builder $query) use ($sourceId): void {
                    $query->where('metadata->source_id', $sourceId)
                        ->orWhere('metadata->legacy_agentic_marketing_opportunity_id', $sourceId)
                        ->orWhere('metadata->agentic_marketing_opportunity_id', $sourceId)
                        ->orWhere('metadata->promotion->legacy_agentic_marketing_opportunity_id', $sourceId)
                        ->orWhere('evidence->legacy_agentic_marketing_opportunity->source_id', $sourceId);
                });
            })
            ->when($filters['detector'] ?? null, function (Builder $query, string $detector): Builder {
                return $query->where(function (Builder $query) use ($detector): void {
                    $query->where('metadata->detector_key', $detector)
                        ->orWhere('metadata->promotion->detector_key', $detector)
                        ->orWhere('metrics->detector_key', $detector)
                        ->orWhere('evidence->detector_key', $detector);
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
        $blockedReasons = $this->blockedReasons($signal);
        $sourceId = $this->sourceId($signal);
        $sourceExists = filled($sourceId) && AgenticMarketingOpportunity::query()->whereKey($sourceId)->exists();
        $linkedOpportunityIds = $signal->opportunities
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        return [
            'signal_id' => (string) $signal->id,
            'workspace_id' => (string) $signal->workspace_id,
            'client_site_id' => $signal->client_site_id ? (string) $signal->client_site_id : null,
            'content_id' => $signal->content_id ? (string) $signal->content_id : null,
            'objective_id' => $this->objectiveId($signal),
            'legacy_agentic_opportunity_id' => $sourceId,
            'detector_key' => $this->detectorKey($signal) ?: 'unknown',
            'agentic_type' => $this->agenticType($signal) ?: 'unknown',
            'source' => (string) $signal->getRawOriginal('source'),
            'category' => (string) $signal->getRawOriginal('category'),
            'dedupe_hash' => (string) $signal->dedupe_hash,
            'signal_strength' => (float) $signal->signal_strength,
            'confidence' => (float) $signal->confidence,
            'evidence_complete' => $this->evidenceComplete($signal),
            'metadata_complete' => $this->metadataComplete($signal),
            'source_row_exists' => $sourceExists,
            'linked_to_canonical_opportunity' => $linkedOpportunityIds !== [],
            'linked_canonical_opportunity_ids' => $linkedOpportunityIds,
            'canonical_opportunity_link_count' => count($linkedOpportunityIds),
            'duplicate_signal_risk' => $this->duplicateSignalRisk($signal),
            'stale_source_risk' => filled($sourceId) && ! $sourceExists,
            'eligible_for_opportunity_intelligence' => $blockedReasons === [],
            'incomplete' => in_array('evidence_incomplete', $blockedReasons, true)
                || in_array('metadata_incomplete', $blockedReasons, true)
                || in_array('objective_missing', $blockedReasons, true)
                || in_array('source_record_missing', $blockedReasons, true)
                || in_array('detector_key_missing', $blockedReasons, true)
                || in_array('agentic_type_missing', $blockedReasons, true),
            'blocked_reasons' => $blockedReasons,
        ];
    }

    private function sourceId(OpportunitySignal $signal): ?string
    {
        $sourceId = data_get($signal->metadata, 'source_id')
            ?: data_get($signal->metadata, 'legacy_agentic_marketing_opportunity_id')
            ?: data_get($signal->metadata, 'agentic_marketing_opportunity_id')
            ?: data_get($signal->metadata, 'promotion.legacy_agentic_marketing_opportunity_id')
            ?: data_get($signal->evidence, 'legacy_agentic_marketing_opportunity.source_id');

        return filled($sourceId) ? (string) $sourceId : null;
    }

    private function sourceModel(OpportunitySignal $signal): ?string
    {
        $sourceModel = data_get($signal->metadata, 'source_model')
            ?: data_get($signal->evidence, 'legacy_agentic_marketing_opportunity.source_model');

        return filled($sourceModel) ? (string) $sourceModel : null;
    }

    private function objectiveId(OpportunitySignal $signal): ?string
    {
        $objectiveId = data_get($signal->metadata, 'objective_id')
            ?: data_get($signal->metadata, 'promotion.objective_id')
            ?: data_get($signal->evidence, 'legacy_agentic_marketing_opportunity.objective_id');

        return filled($objectiveId) ? (string) $objectiveId : null;
    }

    private function detectorKey(OpportunitySignal $signal): ?string
    {
        $detector = data_get($signal->metadata, 'detector_key')
            ?: data_get($signal->metadata, 'promotion.detector_key')
            ?: data_get($signal->metrics, 'detector_key')
            ?: data_get($signal->evidence, 'detector_key');

        return filled($detector) ? (string) $detector : null;
    }

    private function agenticType(OpportunitySignal $signal): ?string
    {
        $type = data_get($signal->metadata, 'agentic_type')
            ?: data_get($signal->metadata, 'opportunity_type')
            ?: data_get($signal->evidence, 'opportunity_type')
            ?: data_get($signal->evidence, 'legacy_agentic_marketing_opportunity.type');

        return filled($type) ? (string) $type : null;
    }

    private function evidenceComplete(OpportunitySignal $signal): bool
    {
        $evidence = $signal->evidence;

        return is_array($evidence)
            && $evidence !== []
            && filled(data_get($evidence, 'detector_key'))
            && filled(data_get($evidence, 'legacy_agentic_marketing_opportunity.source_id'));
    }

    private function metadataComplete(OpportunitySignal $signal): bool
    {
        $metadata = $signal->metadata;

        return is_array($metadata)
            && filled(data_get($metadata, 'source_model'))
            && filled($this->sourceId($signal))
            && filled($this->objectiveId($signal))
            && filled($this->detectorKey($signal))
            && filled($this->agenticType($signal))
            && filled(data_get($metadata, 'source_scoped_dedupe_key'));
    }

    private function duplicateSignalRisk(OpportunitySignal $signal): bool
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
                ->where(function (Builder $query): void {
                    $query->where('metadata->source_type', self::SOURCE_TYPE)
                        ->orWhere('metadata->source_model', AgenticMarketingOpportunity::class)
                        ->orWhere('metadata->promotion->version', 'agentic-opportunity-signal-promotion:v1')
                        ->orWhere('metadata->legacy_agentic_marketing_opportunity_id', '!=', null)
                        ->orWhere('metadata->agentic_marketing_opportunity_id', '!=', null);
                })
                ->where(function (Builder $query) use ($sourceId): void {
                    $query->where('metadata->source_id', $sourceId)
                        ->orWhere('metadata->legacy_agentic_marketing_opportunity_id', $sourceId)
                        ->orWhere('metadata->agentic_marketing_opportunity_id', $sourceId)
                        ->orWhere('metadata->promotion->legacy_agentic_marketing_opportunity_id', $sourceId)
                        ->orWhere('evidence->legacy_agentic_marketing_opportunity->source_id', $sourceId);
                })
                ->whereKeyNot($signal->id)
                ->count();

        return $dedupeMatches > 0 || $sourceMatches > 0;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,int>
     */
    private function breakdown(Collection $rows, string $key): array
    {
        return $rows
            ->groupBy(fn (array $row): string => (string) ($row[$key] ?: 'unknown'))
            ->map(fn (Collection $rows): int => $rows->count())
            ->sortKeys()
            ->all();
    }
}
