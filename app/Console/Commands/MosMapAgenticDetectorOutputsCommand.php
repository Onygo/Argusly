<?php

namespace App\Console\Commands;

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Services\AgenticMarketing\OpportunityDetection\DetectedOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalMappingResult;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosMapAgenticDetectorOutputsCommand extends Command
{
    protected $signature = 'mos:map-agentic-detector-outputs
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum existing AgenticMarketingOpportunity rows to inspect}
        {--sample : Include deterministic sample mappings without running live detectors}';

    protected $description = 'Read-only preview of Agentic Marketing detector output mapping into canonical MOS payloads.';

    public function handle(AgenticOpportunityCanonicalMappingService $mapper): int
    {
        $this->components->info('Read-only Agentic detector output mapping. No detectors, queues, opportunities, or signals will be created.');
        $this->components->warn('Existing AgenticMarketingOpportunity rows are inspected. Live detectors are not run in this diagnostics command.');

        $results = collect();
        $limit = max(1, (int) $this->option('limit'));
        $detectorFilter = $this->detectorFilter();

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($mapper, $detectorFilter, $results): void {
                $result = $mapper->mapExisting($opportunity);
                if ($detectorFilter && $result->detectorKey !== $detectorFilter) {
                    return;
                }

                $results->push($result);
            });

        if ((bool) $this->option('sample')) {
            foreach ($this->sampleResults($mapper) as $result) {
                if ($detectorFilter && $result->detectorKey !== $detectorFilter) {
                    continue;
                }

                $results->push($result);
            }
        }

        $this->renderSummary($results);
        $this->renderRows($results);

        if ($results->isEmpty()) {
            $this->line('No Agentic detector output mappings matched the filters.');
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
            ->when($this->option('objective'), fn (Builder $query, string $objective): Builder => $query->where('objective_id', $objective))
            ->when($this->option('workspace'), function (Builder $query, string $workspace): Builder {
                return $query->whereHas('objective', fn (Builder $objectiveQuery): Builder => $objectiveQuery->where('workspace_id', $workspace));
            })
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }

    /**
     * @return Collection<int,AgenticCanonicalMappingResult>
     */
    private function sampleResults(AgenticOpportunityCanonicalMappingService $mapper): Collection
    {
        $objective = new AgenticMarketingObjective;
        $objective->forceFill([
            'id' => (string) ($this->option('objective') ?: 'sample-objective'),
            'organization_id' => 0,
            'workspace_id' => (string) ($this->option('workspace') ?: 'sample-workspace'),
            'client_site_id' => 'sample-site',
            'name' => 'Sample Agentic objective',
            'goal' => 'Preview canonical mapping',
            'locale' => 'en',
            'status' => 'active',
        ]);

        return collect($mapper->knownDetectorKeys())
            ->map(fn (string $detectorKey): AgenticCanonicalMappingResult => $mapper->map(
                $this->sampleDetectedOpportunity($detectorKey),
                $objective,
                $detectorKey,
            ));
    }

    private function sampleDetectedOpportunity(string $detectorKey): DetectedOpportunity
    {
        $type = match ($detectorKey) {
            'refresh_lifecycle' => AgenticMarketingOpportunityType::Refresh,
            'internal_links' => AgenticMarketingOpportunityType::InternalLinks,
            'localization_gaps' => AgenticMarketingOpportunityType::LocaleExpansion,
            'structured_answer_gaps' => AgenticMarketingOpportunityType::AnswerCoverage,
            'seo_indexability' => AgenticMarketingOpportunityType::SeoIndexability,
            'content_network_gaps' => AgenticMarketingOpportunityType::ContentNetwork,
            'ai_visibility_gaps',
            'llm_tracking_ai_visibility' => AgenticMarketingOpportunityType::AiVisibility,
            'campaign_cluster_action_materializer' => AgenticMarketingOpportunityType::NewArticle,
            default => AgenticMarketingOpportunityType::AiVisibility,
        };

        $signals = match ($detectorKey) {
            'content_network_gaps' => [
                'cluster_id' => 'sample-cluster',
                'cluster_name' => 'AI visibility',
                'topic_keyword' => 'AI visibility',
                'gap_type' => 'weak_cluster_coverage',
            ],
            'campaign_cluster_action_materializer' => [
                'campaign_cluster_id' => 'sample-campaign-cluster',
                'campaign_cluster_item_id' => 'sample-campaign-cluster-item',
                'topic_keyword' => 'AI visibility campaign',
                'asset_kind' => 'content_asset',
            ],
            'llm_tracking_ai_visibility' => [
                'llm_tracking_signal' => 'missing_brand_mentions',
                'query_id' => 123,
                'query_text' => 'What is agentic marketing?',
                'locale' => 'en',
            ],
            default => [
                'topic_keyword' => 'AI visibility',
                'locale' => 'en',
            ],
        };

        return new DetectedOpportunity(
            title: 'Sample '.$detectorKey.' output',
            type: $type,
            priorityScore: 72,
            payload: [
                'detector' => $detectorKey,
                'dedupe_key' => 'sample:'.$detectorKey,
                'content_id' => 'sample-content',
                'client_site_id' => 'sample-site',
                'topic' => 'AI visibility',
                'signals' => $signals,
                'score_explanation' => [
                    'confidence_score' => 70,
                    'impact_score' => 76,
                    'effort_score' => 42,
                    'summary' => 'Sample mapping preview.',
                ],
            ],
            contentId: 'sample-content',
        );
    }

    /**
     * @param  Collection<int,AgenticCanonicalMappingResult>  $results
     */
    private function renderSummary(Collection $results): void
    {
        $summary = [
            'mappings' => $results->count(),
            'signal_capable' => $results->where('canEmitSignal', true)->count(),
            'opportunity_capable' => $results->where('canEmitCanonicalOpportunityCandidate', true)->count(),
            'execution_only' => $results->where('executionOnly', true)->count(),
            'blocked' => $results->filter(fn (AgenticCanonicalMappingResult $result): bool => $result->blockedReasons !== [])->count(),
        ];

        $this->newLine();
        $this->table(
            ['mappings', 'signal-capable', 'opportunity-capable', 'execution-only', 'blocked'],
            [[$summary['mappings'], $summary['signal_capable'], $summary['opportunity_capable'], $summary['execution_only'], $summary['blocked']]]
        );

        $this->line('signal-capable count: '.$summary['signal_capable']);
        $this->line('opportunity-capable count: '.$summary['opportunity_capable']);
        $this->line('execution-only count: '.$summary['execution_only']);
        $this->line('blocked count: '.$summary['blocked']);
    }

    /**
     * @param  Collection<int,AgenticCanonicalMappingResult>  $results
     */
    private function renderRows(Collection $results): void
    {
        if ($results->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Detector mapping readiness');
        $results->each(function (AgenticCanonicalMappingResult $result): void {
            $this->line(sprintf(
                'detector=%s classification=%s signal=%s opportunity=%s risk=%s',
                $result->detectorKey,
                $result->classification->value,
                $result->canEmitSignal ? 'yes' : 'no',
                $result->canEmitCanonicalOpportunityCandidate ? 'yes' : 'no',
                $result->riskLevel,
            ));
        });

        $this->table(
            ['detector', 'classification', 'signal', 'opportunity', 'missing context', 'blocked reasons', 'risk', 'dedupe key sample'],
            $results
                ->map(fn (AgenticCanonicalMappingResult $result): array => [
                    $result->detectorKey,
                    $result->classification->value,
                    $result->canEmitSignal ? 'yes' : 'no',
                    $result->canEmitCanonicalOpportunityCandidate ? 'yes' : 'no',
                    $result->missingContext === [] ? 'none' : implode(', ', $result->missingContext),
                    $result->blockedReasons === [] ? 'none' : implode(', ', $result->blockedReasons),
                    $result->riskLevel,
                    substr($result->dedupeKey, 0, 16),
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
