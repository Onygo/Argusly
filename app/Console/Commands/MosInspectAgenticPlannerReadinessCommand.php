<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerReadinessInspectionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosInspectAgenticPlannerReadinessCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-readiness
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum AgenticMarketingOpportunity rows to inspect}';

    protected $description = 'Read-only diagnostics for future Agentic planner readiness using canonical-linked opportunity context.';

    public function handle(
        AgenticPlannerReadinessInspectionService $inspection,
        AgenticOpportunityCanonicalMappingService $mapping,
    ): int {
        $this->components->info('Read-only Agentic planner readiness diagnostics.');
        $this->components->warn('No planner selection, Agentic actions, canonical recommended actions, dedupe keys, execution parents, lifecycle state, routes, payloads, or historical rows will be updated.');

        $detectorFilter = trim((string) ($this->option('detector') ?: ''));
        $rows = collect();

        $this->query()
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($inspection, $mapping, $detectorFilter, $rows): void {
                if ($detectorFilter !== '' && $mapping->mapExisting($opportunity)->detectorKey !== $detectorFilter) {
                    return;
                }

                $rows->push($inspection->inspect($opportunity));
            });

        $this->renderSummary($rows);
        $this->renderSamples($rows);
        $this->renderRows($rows);

        if ($rows->isEmpty()) {
            $this->line('No Agentic Marketing planner readiness rows matched the filters.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<AgenticMarketingOpportunity>
     */
    private function query(): Builder
    {
        return AgenticMarketingOpportunity::query()
            ->with('objective')
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('objective'), fn (Builder $query, string $objective): Builder => $query->where('objective_id', $objective))
            ->when($this->option('status'), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($this->option('workspace'), function (Builder $query, string $workspace): Builder {
                return $query->whereHas('objective', fn (Builder $objectiveQuery): Builder => $objectiveQuery->where('workspace_id', $workspace));
            })
            ->when($this->option('site'), function (Builder $query, string $site): Builder {
                return $query->where(function (Builder $siteQuery) use ($site): void {
                    $siteQuery->whereHas('objective', fn (Builder $objectiveQuery): Builder => $objectiveQuery->where('client_site_id', $site))
                        ->orWhere('payload->client_site_id', $site)
                        ->orWhere('payload->signals->client_site_id', $site);
                });
            })
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function renderSummary(Collection $rows): void
    {
        $summary = [
            'inspected' => $rows->count(),
            'legacy_only' => $rows->where('readiness_status', AgenticPlannerReadinessInspectionService::STATUS_LEGACY_ONLY)->count(),
            'canonical_context_available' => $rows->where('readiness_status', AgenticPlannerReadinessInspectionService::STATUS_CANONICAL_CONTEXT_AVAILABLE)->count(),
            'metadata_ready_only' => $rows->where('readiness_status', AgenticPlannerReadinessInspectionService::STATUS_METADATA_READY_ONLY)->count(),
            'planner_candidate_blocked' => $rows->where('readiness_status', AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_BLOCKED)->count(),
            'planner_candidate_ready' => $rows->where('readiness_status', AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY)->count(),
            'duplicate_action_risk' => $rows->filter(fn (array $row): bool => (bool) data_get($row, 'duplicate_action_risk.risk'))->count(),
            'lifecycle_ambiguity' => $rows->filter(fn (array $row): bool => (bool) data_get($row, 'phase_3j_lifecycle_action_ownership_status.lifecycle_status_ambiguous'))->count(),
            'continuity_blocker' => $rows->filter(fn (array $row): bool => data_get($row, 'phase_3i_continuity_status.canonical_parent_only_lookup_blockers', []) !== [])->count(),
            'signature_blocker' => $rows->filter(fn (array $row): bool => data_get($row, 'phase_3h_signature_status.blocked_reasons', []) !== [])->count(),
        ];

        $this->newLine();
        $this->table(
            [
                'inspected',
                'legacy only',
                'canonical context available',
                'metadata ready only',
                'planner candidate blocked',
                'planner candidate ready',
                'duplicate action risk',
                'lifecycle ambiguity',
                'continuity blocker',
                'signature blocker',
            ],
            [[
                $summary['inspected'],
                $summary['legacy_only'],
                $summary['canonical_context_available'],
                $summary['metadata_ready_only'],
                $summary['planner_candidate_blocked'],
                $summary['planner_candidate_ready'],
                $summary['duplicate_action_risk'],
                $summary['lifecycle_ambiguity'],
                $summary['continuity_blocker'],
                $summary['signature_blocker'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).' count: '.$value);
        }
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function renderSamples(Collection $rows): void
    {
        $priorityDifferences = $rows
            ->filter(fn (array $row): bool => data_get($row, 'priority_provenance.difference') !== null)
            ->map(fn (array $row): string => sprintf(
                '%s legacy=%s canonical=%s diff=%s',
                $row['legacy_agentic_opportunity_id'],
                $row['legacy_priority_score'],
                $row['canonical_priority_score'],
                data_get($row, 'priority_provenance.difference')
            ))
            ->take(5)
            ->values()
            ->all();
        $blockedReasons = $rows
            ->flatMap(fn (array $row): array => $row['readiness_blocked_reasons'])
            ->unique()
            ->take(10)
            ->values()
            ->all();
        $readinessSamples = $rows
            ->map(fn (array $row): string => $row['legacy_agentic_opportunity_id'].':'.$row['readiness_status'])
            ->take(10)
            ->values()
            ->all();

        $this->line('sample legacy vs canonical priority differences: '.($priorityDifferences === [] ? 'none' : implode(' | ', $priorityDifferences)));
        $this->line('blocked reason samples: '.($blockedReasons === [] ? 'none' : implode(', ', $blockedReasons)));
        $this->line('readiness samples: '.($readinessSamples === [] ? 'none' : implode(', ', $readinessSamples)));
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function renderRows(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->table(
            ['agentic id', 'canonical id', 'objective', 'workspace', 'detector', 'type', 'legacy priority', 'canonical priority', 'open actions', 'readiness', 'blocked reasons'],
            $rows
                ->map(fn (array $row): array => [
                    $row['legacy_agentic_opportunity_id'],
                    $row['linked_canonical_opportunity_id'] ?: 'none',
                    $row['objective_id'] ?: 'missing',
                    $row['workspace_id'] ?: 'missing',
                    $row['detector_key'] ?: 'unknown',
                    $row['agentic_type'] ?: 'unknown',
                    $row['legacy_priority_score'],
                    $row['canonical_priority_score'] ?? 'none',
                    $row['open_legacy_actions_count'],
                    $row['readiness_status'],
                    $row['readiness_blocked_reasons'] === [] ? 'none' : implode(', ', array_slice($row['readiness_blocked_reasons'], 0, 3)),
                ])
                ->all()
        );
    }
}
