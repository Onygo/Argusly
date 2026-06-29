<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticExecutionCanonicalMetadataResolver;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosPlanAgenticExecutionCanonicalMetadataCommand extends Command
{
    protected $signature = 'mos:plan-agentic-execution-canonical-metadata
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum existing AgenticMarketingOpportunity rows to inspect}';

    protected $description = 'Read-only diagnostics for future Agentic execution canonical metadata.';

    public function handle(
        AgenticExecutionCanonicalMetadataResolver $resolver,
        AgenticOpportunityCanonicalMappingService $mapping,
    ): int {
        $this->components->info('Read-only Agentic execution canonical metadata diagnostics.');
        $this->components->warn('No historical actions, action runs, pipelines, assets, briefs, drafts, approvals, feedback, audit logs, or rollback snapshots will be updated.');

        $detectorFilter = trim((string) ($this->option('detector') ?: ''));
        $rows = collect();

        $this->query()
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($resolver, $mapping, $detectorFilter, $rows): void {
                if ($detectorFilter !== '' && $mapping->mapExisting($opportunity)->detectorKey !== $detectorFilter) {
                    return;
                }

                $rows->push($resolver->resolve($opportunity, 'pipeline'));
            });

        $this->renderSummary($rows);
        $this->renderSamples($rows);
        $this->renderRows($rows);

        if ($rows->isEmpty()) {
            $this->line('No Agentic Marketing canonical metadata rows matched the filters.');
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
            'linked_canonical' => $rows->whereNotNull('canonical_opportunity_id')->count(),
            'metadata_safe' => $rows->where('safe', true)->count(),
            'blocked' => $rows->where('safe', false)->count(),
            'duplicate_bridge' => $this->countReason($rows, 'multiple_canonical_opportunities_linked_to_agentic_row'),
            'workspace_mismatch' => $this->countReason($rows, 'canonical_bridge_workspace_mismatch'),
            'lifecycle_ambiguity' => $this->countReason($rows, 'phase_3j_lifecycle_status_ambiguous'),
            'phase_3i_continuity_blocker' => $this->countReason($rows, 'phase_3i_execution_continuity_blocked'),
        ];

        $this->newLine();
        $this->table(
            ['inspected', 'linked canonical', 'metadata safe', 'blocked', 'duplicate bridge', 'workspace mismatch', 'lifecycle ambiguity', 'Phase 3I blockers'],
            [[
                $summary['inspected'],
                $summary['linked_canonical'],
                $summary['metadata_safe'],
                $summary['blocked'],
                $summary['duplicate_bridge'],
                $summary['workspace_mismatch'],
                $summary['lifecycle_ambiguity'],
                $summary['phase_3i_continuity_blocker'],
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
        $metadataSamples = $rows
            ->where('safe', true)
            ->map(fn (array $row): string => json_encode($row['metadata']))
            ->filter()
            ->take(3)
            ->values()
            ->all();
        $blockedReasons = $rows
            ->flatMap(fn (array $row): array => $row['blocked_reasons'])
            ->unique()
            ->take(10)
            ->values()
            ->all();
        $targetFields = $rows
            ->flatMap(fn (array $row): array => $row['target_fields'])
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $this->line('proposed metadata samples: '.($metadataSamples === [] ? 'none' : implode(' | ', $metadataSamples)));
        $this->line('blocked reason samples: '.($blockedReasons === [] ? 'none' : implode(', ', $blockedReasons)));
        $this->line('target field samples: '.($targetFields === [] ? 'none' : implode(', ', $targetFields)));
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
            ['agentic id', 'canonical id', 'safe', 'context', 'blocked reasons'],
            $rows
                ->map(fn (array $row): array => [
                    $row['legacy_agentic_opportunity_id'],
                    $row['canonical_opportunity_id'] ?: 'none',
                    $row['safe'] ? 'yes' : 'no',
                    $row['execution_context'] ?: 'none',
                    $row['blocked_reasons'] === [] ? 'none' : implode(', ', array_slice($row['blocked_reasons'], 0, 3)),
                ])
                ->all()
        );
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function countReason(Collection $rows, string $reason): int
    {
        return $rows
            ->filter(fn (array $row): bool => in_array($reason, $row['blocked_reasons'], true))
            ->count();
    }
}
