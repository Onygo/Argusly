<?php

namespace App\Console\Commands;

use App\Services\OpportunityIntelligence\CompetitorOpportunitySignalValidationService;
use Illuminate\Console\Command;

class MosValidateCompetitorOpportunitySignalsCommand extends Command
{
    protected $signature = 'mos:validate-competitor-opportunity-signals
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Limit to one CompetitorContentOpportunity id}
        {--limit=100 : Maximum promoted signals to inspect}';

    protected $description = 'Inspect promoted competitor OpportunitySignals for canonical opportunity intelligence readiness.';

    public function handle(CompetitorOpportunitySignalValidationService $validator): int
    {
        $report = $validator->inspect([
            'workspace' => $this->option('workspace'),
            'site' => $this->option('site'),
            'source_id' => $this->option('source-id'),
            'limit' => (int) $this->option('limit'),
        ]);

        $this->components->info('Promoted competitor opportunity signal validation');
        $this->components->warn('Inspection only. OpportunityIntelligenceEngine has no dry-run mode, so this command does not create or update opportunities.');

        $summary = $report['summary'];

        $this->table(
            ['signals', 'eligible', 'linked', 'unclustered', 'incomplete', 'duplicates', 'stale'],
            [[
                $summary['signals'],
                $summary['eligible'],
                $summary['linked'],
                $summary['unclustered'],
                $summary['incomplete'],
                $summary['duplicates'],
                $summary['stale'],
            ]]
        );

        $this->line('Eligible signals: '.$summary['eligible']);
        $this->line('Linked signals: '.$summary['linked']);
        $this->line('Unclustered eligible signals: '.$summary['unclustered']);
        $this->line('Incomplete signals: '.$summary['incomplete']);

        if ($report['rows'] !== []) {
            $this->newLine();
            $this->components->info('Signal readiness');
            $this->table(
                ['signal id', 'workspace id', 'site id', 'source id', 'topic', 'entity', 'linked', 'issues'],
                collect($report['rows'])
                    ->map(fn (array $row): array => [
                        $row['signal_id'],
                        $row['workspace_id'],
                        $row['site_id'],
                        $row['source_id'] ?: 'unknown',
                        $row['topic'] ?: 'unknown',
                        $row['entity'] ?: 'unknown',
                        $row['linked'] ? 'yes' : 'no',
                        $row['issues'] === [] ? 'none' : implode(', ', $row['issues']),
                    ])
                    ->all()
            );
        }

        if ($report['issues'] !== []) {
            $this->newLine();
            $this->components->warn('Incomplete or risky signals');
            $this->table(
                ['signal id', 'source id', 'issue'],
                collect($report['issues'])
                    ->map(fn (array $issue): array => [
                        $issue['signal_id'],
                        $issue['source_id'],
                        $issue['issue'],
                    ])
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
