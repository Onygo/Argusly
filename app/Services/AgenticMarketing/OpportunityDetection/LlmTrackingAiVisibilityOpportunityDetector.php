<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\Content;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\LlmTrackingQuerySet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LlmTrackingAiVisibilityOpportunityDetector implements AgenticMarketingOpportunityDetector
{
    public function detect(AgenticMarketingObjective $objective): array
    {
        $queries = $this->queries($objective);
        $contentByUrl = $this->contentByUrl($objective, $queries);

        return collect()
            ->merge($queries->flatMap(fn (LlmTrackingQuery $query): array => $this->queryOpportunities($query, $contentByUrl)))
            ->merge($this->querySetCoverageOpportunities($objective))
            ->merge($this->localeGapOpportunities($objective, $queries))
            ->values()
            ->all();
    }

    /**
     * @param Collection<string,Content> $contentByUrl
     * @return array<int,DetectedOpportunity>
     */
    private function queryOpportunities(LlmTrackingQuery $query, Collection $contentByUrl): array
    {
        /** @var LlmTrackingQueryRun|null $run */
        $run = $query->latestRun;
        if (! $run || $run->status !== 'succeeded') {
            return [];
        }

        $opportunities = [];
        $visibilityScore = $this->score($run->ai_visibility_score);
        $presenceScore = $this->score($run->presence_score);
        $citationScore = $this->score($run->citation_score);
        $competitorShare = $this->score($run->competitor_share_score);
        $brandMentioned = (bool) $run->brand_mentioned;
        $urlsCited = (bool) $run->urls_cited || $citationScore >= 0.2 || count((array) $run->url_hits) > 0;
        $competitorMentions = count((array) $run->competitor_hits);
        $brandMentions = count((array) $run->brand_hits);
        $matchedContent = $this->matchedContent($query, $contentByUrl);

        if ($visibilityScore > 0 && $visibilityScore < 0.6) {
            $opportunities[] = $this->opportunity(
                query: $query,
                run: $run,
                signalType: 'weak_brand_entity_visibility',
                title: 'Improve brand/entity visibility for "' . Str::limit($query->query_text, 80) . '"',
                priorityBase: 66,
                signals: [
                    'ai_visibility_score' => (int) round($visibilityScore * 100),
                    'presence_score' => (int) round($presenceScore * 100),
                    'entity_presence' => (array) $run->entity_presence,
                    'detected_brands' => (array) $run->detected_brands,
                ],
                content: $matchedContent,
            );
        }

        if (! $brandMentioned) {
            $opportunities[] = $this->opportunity(
                query: $query,
                run: $run,
                signalType: 'missing_brand_mentions',
                title: 'Recover missing brand mention for "' . Str::limit($query->query_text, 80) . '"',
                priorityBase: 70,
                signals: [
                    'brand_mentioned' => false,
                    'brand_terms' => (array) $query->brand_terms,
                    'detected_brands' => (array) $run->detected_brands,
                    'ai_visibility_score' => (int) round($visibilityScore * 100),
                ],
                content: $matchedContent,
            );
        }

        if ((bool) $run->competitors_mentioned && ($competitorShare < 0.45 || $competitorMentions > $brandMentions)) {
            $opportunities[] = $this->opportunity(
                query: $query,
                run: $run,
                signalType: 'competitor_dominance',
                title: 'Counter competitor dominance for "' . Str::limit($query->query_text, 80) . '"',
                priorityBase: 68,
                signals: [
                    'competitor_share_score' => (int) round($competitorShare * 100),
                    'brand_mentions' => $brandMentions,
                    'competitor_mentions' => $competitorMentions,
                    'competitor_hits' => (array) $run->competitor_hits,
                    'detected_competitors' => (array) $run->detected_competitors,
                ],
                content: $matchedContent,
            );
        }

        if (! $urlsCited && $query->target_urls) {
            $opportunities[] = $this->opportunity(
                query: $query,
                run: $run,
                signalType: 'missing_owned_citations',
                title: 'Earn owned citation coverage for "' . Str::limit($query->query_text, 80) . '"',
                priorityBase: 64,
                signals: [
                    'urls_cited' => false,
                    'citation_score' => (int) round($citationScore * 100),
                    'target_urls' => (array) $query->target_urls,
                    'detected_domains' => (array) $run->detected_domains,
                    'sources' => (array) $run->sources,
                ],
                content: $matchedContent,
            );
        }

        if ($this->importantQuery($query) && $matchedContent && $this->needsAnswerBlocks($matchedContent)) {
            $opportunities[] = $this->opportunity(
                query: $query,
                run: $run,
                signalType: 'missing_answer_blocks_for_important_query',
                title: 'Add answer blocks for important query "' . Str::limit($query->query_text, 80) . '"',
                type: AgenticMarketingOpportunityType::AnswerCoverage,
                priorityBase: 62,
                signals: [
                    'query_priority' => (int) ($query->priority ?? 50),
                    'answer_block_score' => (int) ($matchedContent->answer_block_score ?? 0),
                    'answer_block_generation_persisted_count' => (int) ($matchedContent->answer_block_generation_persisted_count ?? 0),
                    'query_text' => (string) $query->query_text,
                ],
                content: $matchedContent,
            );
        }

        return $opportunities;
    }

    /**
     * @return array<int,DetectedOpportunity>
     */
    private function querySetCoverageOpportunities(AgenticMarketingObjective $objective): array
    {
        return LlmTrackingQuerySet::query()
            ->withCount(['queries as active_queries_count' => fn ($query) => $query->where('is_active', true)])
            ->where('workspace_id', $objective->workspace_id)
            ->when($objective->client_site_id, fn ($query) => $query->where('client_site_id', $objective->client_site_id))
            ->where('is_active', true)
            ->get()
            ->filter(fn (LlmTrackingQuerySet $set): bool => (int) $set->active_queries_count < 3)
            ->map(fn (LlmTrackingQuerySet $set): DetectedOpportunity => new DetectedOpportunity(
                title: 'Expand AI visibility query coverage for ' . (string) $set->name,
                type: AgenticMarketingOpportunityType::AiVisibility,
                priorityScore: 58,
                payload: [
                    'detector' => 'llm_tracking_ai_visibility',
                    'signal_type' => 'query_set_coverage_gap',
                    'dedupe_key' => 'query-set:' . $set->id . ':coverage',
                    'signals' => [
                        'query_set_id' => (int) $set->id,
                        'query_set_name' => (string) $set->name,
                        'active_queries_count' => (int) $set->active_queries_count,
                        'locale' => (string) $set->locale,
                    ],
                    'references' => [
                        'llm_tracking_query_set_id' => (int) $set->id,
                    ],
                ],
            ))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int,LlmTrackingQuery> $queries
     * @return array<int,DetectedOpportunity>
     */
    private function localeGapOpportunities(AgenticMarketingObjective $objective, Collection $queries): array
    {
        return $queries
            ->groupBy(fn (LlmTrackingQuery $query): string => (string) ($query->locale ?: 'en'))
            ->map(function (Collection $localeQueries, string $locale) use ($objective): ?DetectedOpportunity {
                $runs = $localeQueries->pluck('latestRun')->filter();
                if ($runs->isEmpty()) {
                    return null;
                }

                $average = $runs
                    ->map(fn (LlmTrackingQueryRun $run): float => $this->score($run->ai_visibility_score))
                    ->filter(fn (float $score): bool => $score > 0)
                    ->avg();

                if ($average === null || $average >= 0.6) {
                    return null;
                }

                return new DetectedOpportunity(
                    title: 'Improve ' . strtoupper($locale) . ' AI visibility coverage',
                    type: AgenticMarketingOpportunityType::AiVisibility,
                    priorityScore: 60,
                    payload: [
                        'detector' => 'llm_tracking_ai_visibility',
                        'signal_type' => 'locale_ai_visibility_gap',
                        'dedupe_key' => 'objective:' . $objective->id . ':locale:' . $locale,
                        'signals' => [
                            'locale' => $locale,
                            'ai_visibility_score' => (int) round($average * 100),
                            'query_count' => $localeQueries->count(),
                            'query_ids' => $localeQueries->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                        ],
                        'references' => [
                            'llm_tracking_query_ids' => $localeQueries->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                            'llm_tracking_query_run_ids' => $runs->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                        ],
                    ],
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    private function opportunity(
        LlmTrackingQuery $query,
        LlmTrackingQueryRun $run,
        string $signalType,
        string $title,
        int $priorityBase,
        array $signals,
        ?Content $content = null,
        AgenticMarketingOpportunityType $type = AgenticMarketingOpportunityType::AiVisibility,
    ): DetectedOpportunity {
        return new DetectedOpportunity(
            title: $title,
            type: $type,
            priorityScore: (int) round($priorityBase + min(12, max(0, (int) ($query->priority ?? 50) - 50) / 4)),
            payload: [
                'detector' => 'llm_tracking_ai_visibility',
                'signal_type' => $signalType,
                'dedupe_key' => 'query:' . $query->id . ':' . $signalType . ($content ? ':content:' . $content->id : ''),
                'content_id' => $content ? (string) $content->id : null,
                'signals' => array_merge($signals, [
                    'llm_tracking_signal' => $signalType,
                    'query_id' => (int) $query->id,
                    'query_set_id' => $query->llm_tracking_query_set_id ? (int) $query->llm_tracking_query_set_id : null,
                    'query_text' => (string) $query->query_text,
                    'locale' => (string) ($query->locale ?: 'en'),
                    'provider' => (string) ($run->provider ?? ''),
                    'model' => (string) ($run->model ?? ''),
                ]),
                'references' => [
                    'llm_tracking_query_id' => (int) $query->id,
                    'llm_tracking_query_run_id' => (int) $run->id,
                    'llm_tracking_query_set_id' => $query->llm_tracking_query_set_id ? (int) $query->llm_tracking_query_set_id : null,
                    'target_brand' => (string) ($query->target_brand ?: collect((array) $query->brand_terms)->first()),
                    'brand_terms' => (array) $query->brand_terms,
                    'competitor_terms' => (array) $query->competitor_terms,
                    'content_urls' => (array) $query->target_urls,
                    'matched_content_id' => $content ? (string) $content->id : null,
                    'matched_content_url' => $content ? (string) ($content->published_url ?: $content->seo_canonical) : null,
                ],
            ],
            contentId: $content ? (string) $content->id : null,
        );
    }

    /**
     * @return Collection<int,LlmTrackingQuery>
     */
    private function queries(AgenticMarketingObjective $objective): Collection
    {
        $locales = collect((array) ($objective->languages ?: [$objective->locale ?: 'en']))
            ->push($objective->locale ?: 'en')
            ->map(fn (mixed $locale): string => trim((string) $locale))
            ->filter()
            ->unique()
            ->values();

        return LlmTrackingQuery::query()
            ->with(['latestRun', 'querySet'])
            ->where('workspace_id', $objective->workspace_id)
            ->when($objective->client_site_id, fn ($query) => $query->where('client_site_id', $objective->client_site_id))
            ->when($locales->isNotEmpty(), fn ($query) => $query->whereIn('locale', $locales->all()))
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->limit(200)
            ->get();
    }

    /**
     * @param Collection<int,LlmTrackingQuery> $queries
     * @return Collection<string,Content>
     */
    private function contentByUrl(AgenticMarketingObjective $objective, Collection $queries): Collection
    {
        $urls = $queries
            ->flatMap(fn (LlmTrackingQuery $query): array => (array) $query->target_urls)
            ->map(fn (mixed $url): string => $this->normalizeUrl((string) $url))
            ->filter()
            ->unique()
            ->values();

        if ($urls->isEmpty()) {
            return collect();
        }

        return Content::query()
            ->where('workspace_id', $objective->workspace_id)
            ->when($objective->client_site_id, fn ($query) => $query->where('client_site_id', $objective->client_site_id))
            ->get(['id', 'workspace_id', 'client_site_id', 'title', 'published_url', 'seo_canonical', 'answer_block_score', 'answer_block_generation_persisted_count'])
            ->flatMap(function (Content $content): array {
                return collect([$content->published_url, $content->seo_canonical])
                    ->map(fn (mixed $url): string => $this->normalizeUrl((string) $url))
                    ->filter()
                    ->mapWithKeys(fn (string $url): array => [$url => $content])
                    ->all();
            })
            ->only($urls->all());
    }

    /**
     * @param Collection<string,Content> $contentByUrl
     */
    private function matchedContent(LlmTrackingQuery $query, Collection $contentByUrl): ?Content
    {
        foreach ((array) $query->target_urls as $url) {
            $content = $contentByUrl->get($this->normalizeUrl((string) $url));
            if ($content) {
                return $content;
            }
        }

        return null;
    }

    private function importantQuery(LlmTrackingQuery $query): bool
    {
        return (int) ($query->priority ?? 50) >= 70;
    }

    private function needsAnswerBlocks(Content $content): bool
    {
        return (int) ($content->answer_block_generation_persisted_count ?? 0) === 0
            || (int) ($content->answer_block_score ?? 100) < 65;
    }

    private function score(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        $score = (float) $value;

        return $score > 1 ? $score / 100 : $score;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim(Str::lower($url));
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        $host = trim((string) ($parts['host'] ?? ''));
        $path = '/' . trim((string) ($parts['path'] ?? ''), '/');

        return $host !== '' ? $host . rtrim($path, '/') : rtrim($url, '/');
    }
}
