<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunityRun;
use App\Services\Mos\Opportunity\ContentOpportunityRunCanonicalReferenceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosInspectContentOpportunityRunLinksCommand extends Command
{
    protected $signature = 'mos:inspect-content-opportunity-run-links
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--run-id= : Inspect one ContentOpportunityRun id}
        {--status= : Limit inspected legacy candidate rows to a ContentOpportunity status}
        {--limit=50 : Maximum runs to inspect}
        {--write-summary : Store the canonical reference summary in content_opportunity_runs.result}';

    protected $description = 'Inspect canonical Opportunity links for ContentOpportunityRun legacy candidates.';

    public function handle(ContentOpportunityRunCanonicalReferenceService $references): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $legacyStatus = $this->option('status') ? (string) $this->option('status') : null;
        $writeSummary = (bool) $this->option('write-summary');
        $summary = [
            'runs' => 0,
            'legacy_opportunities' => 0,
            'linked_canonical_opportunities' => 0,
            'linked_candidates' => 0,
            'unlinked_legacy_opportunities' => 0,
            'duplicate_link_risks' => 0,
            'missing_context' => 0,
            'summaries_written' => 0,
        ];
        $rows = [];

        $this->components->info($writeSummary
            ? 'Writing canonical reference summaries only. Legacy counts, statuses, candidates, briefs, routes and queues will not be changed.'
            : 'Read-only run link inspection. No records will be updated.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunityRun $run) use ($references, $legacyStatus, $writeSummary, &$summary, &$rows): void {
                $result = $references->inspect($run, $legacyStatus);
                $summary['runs']++;
                $summary['legacy_opportunities'] += (int) $result['legacy_opportunity_count'];
                $summary['linked_canonical_opportunities'] += (int) $result['linked_canonical_opportunity_count'];
                $summary['linked_candidates'] += (int) $result['linked_candidate_count'];
                $summary['unlinked_legacy_opportunities'] += (int) $result['unlinked_candidate_count'];
                $summary['duplicate_link_risks'] += (int) $result['duplicate_link_risk_count'];
                $summary['missing_context'] += (int) $result['missing_context_count'];

                if ($writeSummary) {
                    $references->writeSummary($run, $result);
                    $summary['summaries_written']++;
                }

                $rows[] = [
                    $result['run_id'],
                    $result['run_status'],
                    $result['workspace_id'] ?? 'missing',
                    $result['client_site_id'] ?? 'missing',
                    $result['legacy_opportunity_count'],
                    $result['linked_candidate_count'],
                    $result['unlinked_candidate_count'],
                    $result['linked_canonical_opportunity_count'],
                    $result['duplicate_link_risk_count'],
                    $result['missing_context_count'],
                    implode(', ', $result['canonical_opportunity_id_samples']),
                ];
            });

        $this->newLine();
        $this->table(
            ['runs inspected', 'legacy opportunities', 'linked canonical opportunities', 'linked candidates', 'unlinked legacy opportunities', 'duplicate link risks', 'missing context', 'summaries written'],
            [[
                $summary['runs'],
                $summary['legacy_opportunities'],
                $summary['linked_canonical_opportunities'],
                $summary['linked_candidates'],
                $summary['unlinked_legacy_opportunities'],
                $summary['duplicate_link_risks'],
                $summary['missing_context'],
                $summary['summaries_written'],
            ]],
        );

        if ($rows !== []) {
            $this->newLine();
            $this->table(
                ['run id', 'run status', 'workspace id', 'site id', 'legacy candidates', 'linked candidates', 'unlinked candidates', 'canonical links', 'duplicate risks', 'missing context', 'canonical id samples'],
                $rows,
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<ContentOpportunityRun>
     */
    private function query(): Builder
    {
        return ContentOpportunityRun::query()
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('run-id'), fn (Builder $query, string $runId): Builder => $query->whereKey($runId))
            ->when($this->option('status'), function (Builder $query, string $status): Builder {
                return $query->whereHas('opportunities', fn (Builder $opportunities): Builder => $opportunities->where('status', $status));
            })
            ->orderByDesc('created_at')
            ->orderBy('id');
    }
}
