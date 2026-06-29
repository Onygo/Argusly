<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRolloutReadinessService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionRolloutReadinessCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-rollout-readiness
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--include-metadata-only-ok : Include metadata_only_ok row samples}';

    protected $description = 'Read-only Phase 3T readiness inspection for scoped multi-objective Agentic planner default-selection rollout.';

    public function handle(AgenticPlannerDefaultSelectionRolloutReadinessService $readiness): int
    {
        $workspace = trim((string) $this->option('workspace'));
        $limit = trim((string) $this->option('limit'));
        $objectives = trim((string) $this->option('objectives'));

        if ($workspace === '') {
            $this->components->error('The --workspace option is required.');

            return self::INVALID;
        }

        if ($limit === '') {
            $this->components->error('The --limit option is required.');

            return self::INVALID;
        }

        if ($objectives === '') {
            $this->components->error('The --objectives option is required.');

            return self::INVALID;
        }

        $this->components->info('Read-only Phase 3T Agentic planner default-selection rollout readiness.');
        $this->components->warn('No default planner behaviour, action owner, action status, dedupe hash, lifecycle state, route, execution pipeline, historical payload, metadata, or job dispatch will be changed.');

        $report = $readiness->inspect([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'include_metadata_only_ok' => (bool) $this->option('include-metadata-only-ok'),
        ]);

        $this->renderSummary($report);
        $this->renderSamples($report, (bool) $this->option('include-metadata-only-ok'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderSummary(array $report): void
    {
        $summary = (array) $report['summary'];

        $this->newLine();
        $this->table(
            [
                'inspected objectives',
                'ready objectives',
                'blocked objectives',
                'keep single-objective',
                'Phase 3S clean',
                'Phase 3S risky',
                'metadata only ok',
                'Phase 3O risky',
                'preview blocked',
                'shadow blocked',
                'readiness blocked',
                'signature risks',
                'continuity risks',
                'lifecycle risks',
                'duplicate risks',
                'insufficient coverage',
                'order mismatch',
                'candidate actions',
                'Phase 3R metadata',
                'Phase 3N metadata',
                'recommendation',
            ],
            [[
                $summary['inspected_objective_count'],
                $summary['ready_objective_count'],
                $summary['blocked_objective_count'],
                $summary['keep_single_objective_count'],
                $summary['phase_3s_clean_count'],
                $summary['phase_3s_risky_count'],
                $summary['metadata_only_ok_count'],
                $summary['phase_3o_risky_count'],
                $summary['preview_blocked_count'],
                $summary['shadow_blocked_count'],
                $summary['readiness_blocked_count'],
                $summary['signature_risk_count'],
                $summary['continuity_risk_count'],
                $summary['lifecycle_risk_count'],
                $summary['duplicate_risk_count'],
                $summary['insufficient_coverage_count'],
                $summary['order_mismatch_count'],
                $summary['candidate_action_count'],
                $summary['existing_phase_3r_metadata_count'],
                $summary['existing_phase_3n_metadata_count'],
                $report['recommendation'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).': '.$value);
        }

        $this->line('rollout readiness status: '.$report['rollout_readiness_status']);
        $this->line('recommendation: '.$report['recommendation']);
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderSamples(array $report, bool $includeMetadataOnlyOk): void
    {
        $rows = collect((array) ($report['objective_rows'] ?? []));
        $ready = $rows
            ->where('rollout_readiness_status', AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION)
            ->take(5)
            ->map(fn (array $row): string => $row['objective_id'])
            ->values()
            ->all();
        $blocked = $rows
            ->reject(fn (array $row): bool => $row['rollout_readiness_status'] === AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION)
            ->take(5)
            ->map(fn (array $row): string => $row['objective_id'].':'.$row['rollout_readiness_status'])
            ->values()
            ->all();
        $reasons = $rows
            ->flatMap(fn (array $row): array => collect((array) ($row['blocked_reasons'] ?? []))
                ->map(fn (string $reason): string => $row['objective_id'].':'.$reason)
                ->all())
            ->take(10)
            ->values()
            ->all();

        $this->line('sample ready objectives: '.$this->sampleLine($ready));
        $this->line('sample blocked objectives: '.$this->sampleLine($blocked));
        $this->line('blocked reason samples: '.$this->sampleLine($reasons));

        if (! $includeMetadataOnlyOk) {
            return;
        }

        $metadataOnly = $rows
            ->flatMap(fn (array $row): array => collect((array) ($row['metadata_only_ok_rows'] ?? []))
                ->map(fn (array $sample): string => $row['objective_id'].':'.($sample['action_id'] ?? 'unknown').':metadata_only_ok')
                ->all())
            ->take(10)
            ->values()
            ->all();

        $this->line('metadata_only_ok samples: '.$this->sampleLine($metadataOnly));
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function sampleLine(array $values): string
    {
        $values = collect($values)
            ->map(fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES))
            ->filter()
            ->values()
            ->all();

        return $values === [] ? 'none' : implode(' | ', $values);
    }
}
