<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerApplyExperimentAuditService;
use Illuminate\Console\Command;

class MosAuditAgenticPlannerApplyExperimentCommand extends Command
{
    protected $signature = 'mos:audit-agentic-planner-apply-experiment
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--detector= : Limit to one detector key}
        {--status= : Limit to one audit status}
        {--action-status= : Limit to one AgenticMarketingAction status}
        {--limit=100 : Maximum Phase 3N action rows to inspect}';

    protected $description = 'Read-only Phase 3O audit for Phase 3N Agentic planner apply experiment metadata.';

    public function handle(AgenticPlannerApplyExperimentAuditService $audit): int
    {
        $this->components->info('Read-only Phase 3O Agentic planner apply experiment audit.');
        $this->components->warn('No default planner behaviour, action owner, status, lifecycle, route, execution pipeline, historical row, or metadata will be changed.');

        $report = $audit->audit($this->filters());

        $this->renderSummary((array) $report['summary']);
        $this->renderSamples((array) $report['rows']);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'workspace' => $this->option('workspace'),
            'objective' => $this->option('objective'),
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'status' => $this->option('status'),
            'action_status' => $this->option('action-status'),
            'limit' => max(1, (int) $this->option('limit')),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->table(
            [
                'inspected',
                'clean',
                'metadata only ok',
                'stale canonical link',
                'missing legacy parent',
                'missing canonical context',
                'bridge mismatch',
                'signature mismatch',
                'readiness regression',
                'duplicate risk',
                'lifecycle risk',
                'continuity risk',
            ],
            [[
                $summary['inspected_action_count'],
                $summary['clean_count'],
                $summary['metadata_only_ok_count'],
                $summary['stale_canonical_link_count'],
                $summary['missing_legacy_parent_count'],
                $summary['missing_canonical_context_count'],
                $summary['bridge_mismatch_count'],
                $summary['signature_mismatch_count'],
                $summary['readiness_regression_count'],
                $summary['duplicate_risk_count'],
                $summary['lifecycle_risk_count'],
                $summary['continuity_risk_count'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).': '.$value);
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function renderSamples(array $rows): void
    {
        $clean = collect($rows)
            ->filter(fn (array $row): bool => in_array($row['audit_status'], [
                AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
                AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK,
            ], true))
            ->take(5)
            ->map(fn (array $row): string => $row['action_id'].':'.$row['audit_status'])
            ->values()
            ->all();
        $risky = collect($rows)
            ->reject(fn (array $row): bool => in_array($row['audit_status'], [
                AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
                AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK,
            ], true))
            ->take(5)
            ->map(fn (array $row): string => $row['action_id'].':'.$row['audit_status'].'('.implode(',', array_slice((array) $row['blocked_reasons'], 0, 3)).')')
            ->values()
            ->all();

        $this->line('sample clean rows: '.$this->sampleLine($clean));
        $this->line('sample risky rows: '.$this->sampleLine($risky));

        if ($rows !== []) {
            $this->newLine();
            $this->table(
                ['action id', 'action status', 'legacy id', 'payload legacy id', 'canonical id', 'objective', 'workspace', 'detector', 'type', 'audit status', 'blocked/warnings'],
                collect($rows)->take(20)->map(fn (array $row): array => [
                    $row['action_id'],
                    $row['action_status'],
                    $row['legacy_opportunity_id'] ?: 'missing',
                    $row['payload_legacy_opportunity_id'] ?: 'missing',
                    $row['canonical_opportunity_id'] ?: 'missing',
                    $row['objective_id'] ?: 'missing',
                    $row['workspace_id'] ?: 'missing',
                    $row['detector_key'] ?: 'unknown',
                    $row['action_type'],
                    $row['audit_status'],
                    $this->sampleLine(array_merge((array) $row['blocked_reasons'], (array) $row['warning_reasons'])),
                ])->all()
            );
        }
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

        return $values === [] ? 'none' : implode(', ', $values);
    }
}
