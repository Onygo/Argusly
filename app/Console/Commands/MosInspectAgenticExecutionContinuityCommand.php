<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityExecutionContinuityService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosInspectAgenticExecutionContinuityCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-execution-continuity
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum existing AgenticMarketingOpportunity rows to inspect}';

    protected $description = 'Read-only diagnostics for Agentic execution parent and canonical reference continuity.';

    public function handle(AgenticOpportunityExecutionContinuityService $continuity): int
    {
        $this->components->info('Read-only Agentic execution continuity diagnostics.');
        $this->components->warn('No execution FKs, route ids, action payloads, pipelines, assets, approvals, feedback, audit logs, or rollback snapshots will be updated.');

        $detectorFilter = $this->detectorFilter();
        $limit = max(1, (int) $this->option('limit'));
        $rows = collect();

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($continuity, $detectorFilter, $rows): void {
                $row = $continuity->inspect($opportunity);
                if ($detectorFilter && $row['detector_key'] !== $detectorFilter) {
                    return;
                }

                $rows->push($row);
            });

        $this->renderSummary($rows);
        $this->renderSamples($rows);
        $this->renderRows($rows);

        if ($rows->isEmpty()) {
            $this->line('No Agentic Marketing execution continuity rows matched the filters.');
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
            'inspected_opportunities' => $rows->count(),
            'linked_canonical' => $rows->whereNotNull('canonical_opportunity_id')->count(),
            'legacy_only' => $rows->whereNull('canonical_opportunity_id')->count(),
            'actions' => (int) $rows->sum('counts.actions'),
            'action_runs' => (int) $rows->sum('counts.action_runs'),
            'execution_pipelines' => (int) $rows->sum('counts.execution_pipelines'),
            'execution_assets' => (int) $rows->sum('counts.execution_assets'),
            'approvals' => (int) $rows->sum('counts.approvals'),
            'feedback' => (int) $rows->sum('counts.feedback'),
            'audit_logs' => (int) $rows->sum('counts.audit_logs'),
            'safe_additive_metadata_candidates' => (int) $rows->sum(fn (array $row): int => count($row['safe_additive_metadata_targets'] ?? [])),
            'blocked' => $rows->filter(fn (array $row): bool => (bool) $row['blocked'])->count(),
        ];

        $this->newLine();
        $this->table(
            ['inspected', 'linked canonical', 'legacy only', 'actions', 'runs', 'pipelines', 'assets', 'approvals', 'feedback', 'audit logs', 'metadata candidates', 'blocked'],
            [[
                $summary['inspected_opportunities'],
                $summary['linked_canonical'],
                $summary['legacy_only'],
                $summary['actions'],
                $summary['action_runs'],
                $summary['execution_pipelines'],
                $summary['execution_assets'],
                $summary['approvals'],
                $summary['feedback'],
                $summary['audit_logs'],
                $summary['safe_additive_metadata_candidates'],
                $summary['blocked'],
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
        $blockedReasons = $rows
            ->flatMap(fn (array $row): array => $row['blocked_reasons'])
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $metadataTargets = $rows
            ->flatMap(fn (array $row): array => collect($row['safe_additive_metadata_targets'])->pluck('target')->all())
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $routeSamples = $rows
            ->flatMap(fn (array $row): array => $row['route_parent_dependency_samples'])
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $this->line('blocked reasons: '.($blockedReasons === [] ? 'none' : implode(', ', $blockedReasons)));
        $this->line('safe additive metadata targets: '.($metadataTargets === [] ? 'none' : implode(', ', $metadataTargets)));
        $this->line('route/parent dependency samples: '.($routeSamples === [] ? 'none' : implode(' | ', $routeSamples)));
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
            ['agentic id', 'canonical id', 'objective id', 'workspace id', 'detector', 'actions', 'runs', 'pipelines', 'assets', 'approvals', 'blocked reasons'],
            $rows
                ->map(fn (array $row): array => [
                    $row['legacy_agentic_opportunity_id'],
                    $row['canonical_opportunity_id'] ?: 'none',
                    $row['objective_id'] ?: 'missing',
                    $row['workspace_id'] ?: 'missing',
                    $row['detector_key'] ?: 'unknown',
                    $row['counts']['actions'],
                    $row['counts']['action_runs'],
                    $row['counts']['execution_pipelines'],
                    $row['counts']['execution_assets'],
                    $row['counts']['approvals'],
                    $row['blocked_reasons'] === [] ? 'none' : implode(', ', array_slice($row['blocked_reasons'], 0, 3)),
                ])
                ->all()
        );
    }

    private function detectorFilter(): ?string
    {
        $detector = trim((string) ($this->option('detector') ?: ''));

        return $detector !== '' ? $detector : null;
    }
}
