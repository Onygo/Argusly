<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunitySignalValidationService;
use Illuminate\Console\Command;

class MosValidateAgenticOpportunitySignalsCommand extends Command
{
    protected $signature = 'mos:validate-agentic-opportunity-signals
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum promoted signals to inspect}';

    protected $description = 'Inspect promoted Agentic Marketing OpportunitySignals for canonical opportunity intelligence readiness.';

    public function handle(AgenticOpportunitySignalValidationService $validator): int
    {
        $report = $validator->inspect([
            'workspace' => $this->option('workspace'),
            'objective' => $this->option('objective'),
            'site' => $this->option('site'),
            'source_id' => $this->option('source-id'),
            'detector' => $this->option('detector'),
            'limit' => (int) $this->option('limit'),
        ]);

        $summary = $report['summary'];

        $this->components->info('Promoted Agentic opportunity signal validation');
        $this->components->warn('Inspection only. This command does not create Opportunity records and does not mutate Agentic rows, actions or execution pipelines.');

        $this->table(
            ['signals', 'eligible', 'linked', 'unlinked eligible', 'incomplete', 'duplicates', 'stale'],
            [[
                $summary['total_promoted_agentic_signals'],
                $summary['eligible'],
                $summary['linked'],
                $summary['unlinked_eligible'],
                $summary['incomplete'],
                $summary['duplicate_signal_risk'],
                $summary['stale_source'],
            ]]
        );

        $this->line('Total promoted Agentic signals: '.$summary['total_promoted_agentic_signals']);
        $this->line('Eligible signals: '.$summary['eligible']);
        $this->line('Linked to canonical opportunity: '.$summary['linked']);
        $this->line('Unlinked eligible signals: '.$summary['unlinked_eligible']);
        $this->line('Incomplete signals: '.$summary['incomplete']);
        $this->line('Duplicate signal risk: '.$summary['duplicate_signal_risk']);
        $this->line('Stale source risk: '.$summary['stale_source']);

        $this->renderBreakdown('Detector breakdown', $report['detector_breakdown']);
        $this->renderBreakdown('Category breakdown', $report['category_breakdown']);

        if ($report['rows'] !== []) {
            $this->newLine();
            $this->components->info('Signal readiness');
            $this->table(
                ['signal id', 'workspace id', 'site id', 'source id', 'detector', 'category', 'linked ids', 'eligible', 'blocked reasons'],
                collect($report['rows'])
                    ->map(fn (array $row): array => [
                        $row['signal_id'],
                        $row['workspace_id'],
                        $row['client_site_id'] ?: 'none',
                        $row['legacy_agentic_opportunity_id'] ?: 'unknown',
                        $row['detector_key'] ?: 'unknown',
                        $row['category'] ?: 'unknown',
                        $row['linked_canonical_opportunity_ids'] === [] ? 'none' : implode(', ', $row['linked_canonical_opportunity_ids']),
                        $row['eligible_for_opportunity_intelligence'] ? 'yes' : 'no',
                        $row['blocked_reasons'] === [] ? 'none' : implode(', ', $row['blocked_reasons']),
                    ])
                    ->all()
            );
        }

        if ($summary['sample_blocked_reasons'] !== []) {
            $this->newLine();
            $this->components->warn('Sample blocked reasons');
            foreach ($summary['sample_blocked_reasons'] as $reason) {
                $this->line('- '.$reason);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,int>  $breakdown
     */
    private function renderBreakdown(string $label, array $breakdown): void
    {
        $this->newLine();
        $this->components->info($label);

        if ($breakdown === []) {
            $this->line('none');

            return;
        }

        $this->table(
            ['key', 'count'],
            collect($breakdown)
                ->map(fn (int $count, string $key): array => [$key, $count])
                ->values()
                ->all()
        );
    }
}
