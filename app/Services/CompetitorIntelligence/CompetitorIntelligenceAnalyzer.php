<?php

namespace App\Services\CompetitorIntelligence;

use App\Models\CompetitorContentItem;
use App\Models\CompetitorContentOpportunity;
use App\Models\CompetitorIntelligenceRun;
use App\Models\CompetitorTopicSignal;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompetitorIntelligenceAnalyzer
{
    public function __construct(
        private readonly CompetitorCoverageComparator $coverageComparator,
        private readonly CompetitorOpportunityScorer $opportunityScorer,
        private readonly CompetitorIntelligenceDedupe $dedupe,
        private readonly CompetitorIntelligenceCache $cache,
    ) {}

    public function analyze(Workspace $workspace, ?SiteCompetitor $competitor = null, array $input = []): CompetitorIntelligenceRun
    {
        $run = CompetitorIntelligenceRun::query()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => $competitor?->client_site_id,
            'site_competitor_id' => $competitor?->id,
            'status' => 'running',
            'source_type' => 'internal_import',
            'cache_key' => $this->cache->analysisKey($workspace, $competitor),
            'input' => $input,
            'started_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($workspace, $competitor, $run): void {
                $items = $this->items($workspace, $competitor);
                $signals = $this->upsertTopicSignals($workspace, $items, $competitor);
                $opportunityCount = $this->upsertOpportunities($workspace, $signals, $competitor, $run);

                $run->forceFill([
                    'status' => 'completed',
                    'content_items_count' => $items->count(),
                    'topics_count' => $signals->count(),
                    'opportunities_count' => $opportunityCount,
                    'result' => [
                        'coverage' => [
                            'missing_topics' => $signals->where('coverage_status', 'missing')->count(),
                            'weak_topics' => $signals->where('coverage_status', 'weak')->count(),
                            'covered_topics' => $signals->where('coverage_status', 'covered')->count(),
                        ],
                        'top_topics' => $signals->sortByDesc('opportunity_score')->take(10)->pluck('topic')->values()->all(),
                    ],
                    'finished_at' => now(),
                ])->save();
            });

            $this->cache->forget($workspace, $competitor);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'failure_reason' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $run->refresh();
    }

    /**
     * @return Collection<int, CompetitorContentItem>
     */
    private function items(Workspace $workspace, ?SiteCompetitor $competitor): Collection
    {
        return CompetitorContentItem::query()
            ->where('workspace_id', $workspace->id)
            ->when($competitor, fn ($query) => $query->where('site_competitor_id', $competitor->id))
            ->orderByDesc('imported_at')
            ->get();
    }

    /**
     * @param Collection<int, CompetitorContentItem> $items
     * @return Collection<int, CompetitorTopicSignal>
     */
    private function upsertTopicSignals(Workspace $workspace, Collection $items, ?SiteCompetitor $competitor): Collection
    {
        $grouped = [];

        foreach ($items as $item) {
            foreach ((array) ($item->detected_topics ?? []) as $topic) {
                $topic = trim((string) $topic);
                if ($topic === '') {
                    continue;
                }

                $key = $this->dedupe->topicHash($topic);
                $grouped[$key]['topic'] ??= $topic;
                $grouped[$key]['items'] ??= [];
                $grouped[$key]['items'][] = $item;
            }
        }

        $signals = collect();
        foreach ($grouped as $topicHash => $group) {
            $topic = (string) $group['topic'];
            $topicItems = collect($group['items']);
            $coverage = $this->coverageComparator->compare($workspace, $topic, $competitor ? (string) $competitor->client_site_id : null);
            $intentMix = $topicItems->countBy('query_intent')->all();
            $formats = $topicItems->countBy('content_format')->all();
            $entities = $topicItems
                ->flatMap(fn (CompetitorContentItem $item): array => (array) ($item->detected_entities ?? []))
                ->countBy()
                ->sortDesc()
                ->take(12)
                ->all();
            $examples = $topicItems->take(5)->map(fn (CompetitorContentItem $item): array => [
                'id' => (string) $item->id,
                'title' => $item->title,
                'url' => $item->url,
                'content_format' => $item->content_format,
                'query_intent' => $item->query_intent,
                'has_answer_block_pattern' => $item->has_answer_block_pattern,
            ])->values()->all();

            $opportunityScore = $this->topicOpportunityScore($coverage['coverage_status'], $topicItems->count(), $intentMix, $formats);

            $signal = CompetitorTopicSignal::query()->updateOrCreate(
                [
                    'workspace_id' => (string) $workspace->id,
                    'site_competitor_id' => $competitor?->id,
                    'topic_hash' => $topicHash,
                ],
                [
                    'organization_id' => $workspace->organization_id,
                    'client_site_id' => $competitor?->client_site_id,
                    'topic' => $topic,
                    'competitor_content_count' => $topicItems->count(),
                    'publishlayer_content_count' => $coverage['publishlayer_content_count'],
                    'overlap_score' => $coverage['overlap_score'],
                    'opportunity_score' => $opportunityScore,
                    'coverage_status' => $coverage['coverage_status'],
                    'intent_mix' => $intentMix,
                    'formats' => $formats,
                    'entities' => $entities,
                    'examples' => $examples,
                    'last_seen_at' => now(),
                ]
            );

            $signals->push($signal);
        }

        return $signals;
    }

    /**
     * @param Collection<int, CompetitorTopicSignal> $signals
     */
    private function upsertOpportunities(Workspace $workspace, Collection $signals, ?SiteCompetitor $competitor, CompetitorIntelligenceRun $run): int
    {
        $count = 0;

        foreach ($signals as $signal) {
            foreach ($this->opportunityScorer->score($workspace, $signal, $competitor) as $payload) {
                CompetitorContentOpportunity::query()->updateOrCreate(
                    [
                        'workspace_id' => (string) $workspace->id,
                        'dedupe_hash' => $payload['dedupe_hash'],
                    ],
                    array_merge($payload, [
                        'organization_id' => $workspace->organization_id,
                        'client_site_id' => $competitor?->client_site_id,
                        'site_competitor_id' => $competitor?->id,
                        'competitor_intelligence_run_id' => (string) $run->id,
                        'status' => 'open',
                        'last_seen_at' => now(),
                    ])
                );
                $count++;
            }
        }

        return $count;
    }

    private function topicOpportunityScore(string $coverageStatus, int $itemCount, array $intentMix, array $formats): float
    {
        $base = match ($coverageStatus) {
            'missing' => 72.0,
            'weak' => 58.0,
            default => 24.0,
        };

        $intentBoost = (($intentMix['transactional'] ?? 0) + ($intentMix['comparison'] ?? 0)) * 8.0;
        $formatBoost = (($formats['comparison_page'] ?? 0) + ($formats['implementation_guide'] ?? 0) + ($formats['use_case'] ?? 0)) * 5.0;

        return min(100.0, round($base + min(18.0, $itemCount * 4.0) + $intentBoost + $formatBoost, 2));
    }
}
