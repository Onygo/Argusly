<?php

namespace App\Services\PageIntelligence\Geo;

use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\LlmTrackingQueryRun;
use App\Models\PageGeoObservation;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use App\Services\PageIntelligence\SubmitMonitoredPageAction;
use App\Services\SignalIntelligence\SignalEventIngestor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PageGeoObservationBuilder
{
    public function __construct(
        private readonly SubmitMonitoredPageAction $submitMonitoredPage,
        private readonly GeoVisibilityScoreCalculator $scoreCalculator,
        private readonly PageIntelligenceScoreCalculator $intelligenceScoreCalculator,
        private readonly SignalEventIngestor $signalEventIngestor,
    ) {
    }

    /**
     * @return Collection<int,PageGeoObservation>
     */
    public function buildForRun(LlmTrackingQueryRun $run): Collection
    {
        $run->loadMissing('trackingQuery.workspace', 'trackingQuery.site');
        $query = $run->trackingQuery;
        $workspace = $query?->workspace;

        if (! $query || ! $workspace || $run->status === 'failed') {
            return collect();
        }

        return DB::transaction(function () use ($run, $query, $workspace): Collection {
            $sources = $this->sources($run);
            $mentionedBrands = $this->mentionedTerms((array) $run->detected_brands, (array) $run->brand_hits, 'brand');
            $mentionedCompetitors = $this->mentionedTerms((array) $run->detected_competitors, (array) $run->competitor_hits, 'competitor');
            $targetDomains = $this->targetDomains($query);
            $competitorTerms = $this->normalizedTerms((array) $query->competitor_terms);
            $retention = $this->retentionPolicy((string) $run->provider);
            $summary = $this->answerSummary((string) ($run->normalized_response ?: $run->answer_text), $retention);
            $observations = collect();
            $clientCitedInRun = false;
            $competitorsCitedInRun = false;
            $safeCitationCount = 0;

            foreach ($sources as $index => $source) {
                $url = (string) ($source['url'] ?? '');
                $domain = (string) ($source['domain'] ?? $this->domain($url));
                $clientCited = $this->domainMatches($domain, $targetDomains) || $this->urlMatchesTargets($url, (array) $query->target_urls);
                $competitorsCited = $this->competitorCited($url, $domain, $competitorTerms);
                $citationCount = max(1, (int) ($source['count'] ?? 1));

                $page = null;
                try {
                    $page = $this->submitMonitoredPage->execute(
                        workspace: $workspace,
                        url: $url,
                        site: $query->site,
                        sourceType: 'geo',
                        pageType: 'geo_citation',
                        extraMetadata: [
                            'geo' => [
                                'llm_tracking_query_id' => $query->id,
                                'llm_tracking_query_run_id' => $run->id,
                                'answer_engine' => $this->answerEngine((string) $run->provider),
                            ],
                        ],
                    )->page;
                } catch (InvalidArgumentException) {
                    continue;
                }

                $context = [
                    'client_cited' => $clientCited,
                    'competitors_cited' => $competitorsCited,
                    'citation_count' => $citationCount,
                    'citation_position' => $index + 1,
                ];
                $score = $this->scoreCalculator->calculate($run, $context);

                $observation = $this->upsertObservation($run, [
                    'monitored_page_id' => $page->id,
                    'page_snapshot_id' => $page->latestSnapshot()->value('id'),
                    'query' => (string) $query->query_text,
                    'query_hash' => $this->queryHash((string) $query->query_text),
                    'answer_engine' => $this->answerEngine((string) $run->provider),
                    'provider' => $run->provider,
                    'model' => $run->model,
                    'locale' => $query->locale,
                    'observed_at' => $run->run_at ?? now(),
                    'cited_url' => $page->canonical_url,
                    'cited_url_hash' => hash('sha256', $page->canonical_url),
                    'cited_domain' => $page->domain,
                    'citation_position' => $index + 1,
                    'citation_count' => $citationCount,
                    'mentioned_brands_json' => $mentionedBrands,
                    'mentioned_competitors_json' => $mentionedCompetitors,
                    'client_cited' => $clientCited,
                    'competitors_cited' => $competitorsCited,
                    'brand_mentioned' => (bool) $run->brand_mentioned,
                    'sentiment' => $run->sentiment_label,
                    'topic_ownership_score' => $score['topic_ownership_score'],
                    'consistency_score' => $score['consistency_score'],
                    'geo_visibility_score' => $score['score'],
                    'breakdown_json' => $score['breakdown'],
                    'answer_summary' => $summary,
                    'raw_payload_json' => $this->rawPayload($run, $source, $retention),
                    'retention_policy' => (string) ($retention['policy'] ?? 'summary_only'),
                    'metadata_json' => [
                        'source' => 'llm_tracking',
                        'source_type' => (string) ($source['type'] ?? 'unknown'),
                        'llm_tracking_query_priority' => $query->priority,
                    ],
                ]);
                $observations->push($observation);
                $clientCitedInRun = $clientCitedInRun || $clientCited;
                $competitorsCitedInRun = $competitorsCitedInRun || $competitorsCited;
                $safeCitationCount += (int) $observation->citation_count;
                $this->refreshPageIntelligenceScore($observation);
            }

            $runLevelScore = $this->scoreCalculator->calculate($run, [
                'client_cited' => $clientCitedInRun,
                'competitors_cited' => $competitorsCitedInRun || (bool) $run->competitors_mentioned,
                'citation_count' => $safeCitationCount,
                'citation_position' => null,
            ]);

            $runLevel = $this->upsertObservation($run, [
                'monitored_page_id' => null,
                'page_snapshot_id' => null,
                'query' => (string) $query->query_text,
                'query_hash' => $this->queryHash((string) $query->query_text),
                'answer_engine' => $this->answerEngine((string) $run->provider),
                'provider' => $run->provider,
                'model' => $run->model,
                'locale' => $query->locale,
                'observed_at' => $run->run_at ?? now(),
                'cited_url' => null,
                'cited_url_hash' => $this->runLevelHash($run),
                'cited_domain' => null,
                'citation_position' => null,
                'citation_count' => $safeCitationCount,
                'mentioned_brands_json' => $mentionedBrands,
                'mentioned_competitors_json' => $mentionedCompetitors,
                'client_cited' => $clientCitedInRun,
                'competitors_cited' => $competitorsCitedInRun || (bool) $run->competitors_mentioned,
                'brand_mentioned' => (bool) $run->brand_mentioned,
                'sentiment' => $run->sentiment_label,
                'topic_ownership_score' => $runLevelScore['topic_ownership_score'],
                'consistency_score' => $runLevelScore['consistency_score'],
                'geo_visibility_score' => $runLevelScore['score'],
                'breakdown_json' => $runLevelScore['breakdown'],
                'answer_summary' => $summary,
                'raw_payload_json' => $this->rawPayload($run, ['type' => 'run_level'], $retention),
                'retention_policy' => (string) ($retention['policy'] ?? 'summary_only'),
                'metadata_json' => [
                    'source' => 'llm_tracking',
                    'source_type' => 'run_level',
                    'llm_tracking_query_priority' => $query->priority,
                ],
            ]);
            $observations->push($runLevel);

            $this->emitMovementSignals($run, $runLevel);

            return $observations->values();
        });
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function upsertObservation(LlmTrackingQueryRun $run, array $attributes): PageGeoObservation
    {
        $query = $run->trackingQuery;
        $workspace = $query?->workspace;

        return PageGeoObservation::query()->updateOrCreate(
            [
                'llm_tracking_query_run_id' => $run->id,
                'cited_url_hash' => $attributes['cited_url_hash'],
            ],
            array_replace($attributes, [
                'organization_id' => $workspace?->organization_id,
                'workspace_id' => $query?->workspace_id,
                'client_site_id' => $query?->client_site_id,
                'llm_tracking_query_id' => $query?->id,
            ])
        );
    }

    private function queryHash(string $query): string
    {
        return hash('sha256', mb_strtolower(trim($query)));
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function sources(LlmTrackingQueryRun $run): Collection
    {
        return collect((array) $run->sources)
            ->merge($this->sourcesFromUrlHits((array) $run->url_hits))
            ->map(function (mixed $source): array {
                $source = is_array($source) ? $source : ['url' => (string) $source];
                $url = trim((string) ($source['url'] ?? $source['target_url'] ?? ''));

                return array_replace($source, [
                    'url' => $url,
                    'domain' => (string) ($source['domain'] ?? $this->domain($url)),
                    'position' => (int) ($source['position'] ?? $source['first_position'] ?? 0),
                ]);
            })
            ->filter(fn (array $source): bool => trim((string) ($source['url'] ?? '')) !== '')
            ->sortBy('position')
            ->unique(fn (array $source): string => Str::lower((string) ($source['url'] ?? '')))
            ->values();
    }

    /**
     * @param array<int,array<string,mixed>> $urlHits
     * @return array<int,array<string,mixed>>
     */
    private function sourcesFromUrlHits(array $urlHits): array
    {
        return collect($urlHits)
            ->flatMap(function (array $hit): array {
                $urls = (array) ($hit['matched_urls'] ?? []);
                if ($urls === [] && trim((string) ($hit['target_url'] ?? '')) !== '') {
                    $urls = [(string) $hit['target_url']];
                }

                return collect($urls)->map(fn (string $url): array => [
                    'url' => $url,
                    'position' => (int) ($hit['first_position'] ?? 0),
                    'count' => (int) ($hit['count'] ?? 1),
                    'type' => 'target_url',
                ])->all();
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int,mixed> $detected
     * @param array<int,mixed> $hits
     * @return array<int,array<string,mixed>>
     */
    private function mentionedTerms(array $detected, array $hits, string $type): array
    {
        return collect(array_merge($detected, $hits))
            ->map(function (mixed $item) use ($type): ?array {
                if (is_array($item)) {
                    $term = trim((string) ($item['term'] ?? $item['name'] ?? $item['brand'] ?? $item['competitor'] ?? ''));
                    if ($term === '') {
                        return null;
                    }

                    return [
                        'term' => $term,
                        'type' => (string) ($item['type'] ?? $type),
                        'count' => (int) ($item['count'] ?? 1),
                        'present' => (bool) ($item['present'] ?? true),
                    ];
                }

                $term = trim((string) $item);

                return $term === '' ? null : ['term' => $term, 'type' => $type, 'count' => 1, 'present' => true];
            })
            ->filter()
            ->unique(fn (array $item): string => Str::lower((string) $item['type'].'|'.$item['term']))
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function targetDomains(mixed $query): array
    {
        return collect((array) ($query->target_urls ?? []))
            ->push((string) ($query->target_domain ?? ''))
            ->push((string) ($query->site?->site_url ?? ''))
            ->merge((array) ($query->site?->allowed_domains ?? []))
            ->map(fn (string $value): string => $this->domain($value) ?: Str::lower(trim($value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,mixed> $terms
     * @return array<int,string>
     */
    private function normalizedTerms(array $terms): array
    {
        return collect($terms)
            ->map(fn (mixed $term): string => Str::slug((string) $term))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $targetDomains
     */
    private function domainMatches(string $domain, array $targetDomains): bool
    {
        $domain = Str::lower(trim($domain));

        return $domain !== '' && collect($targetDomains)->contains(function (string $target) use ($domain): bool {
            return $domain === $target || Str::endsWith($domain, '.'.$target);
        });
    }

    /**
     * @param array<int,string> $targetUrls
     */
    private function urlMatchesTargets(string $url, array $targetUrls): bool
    {
        $normalized = Str::lower(rtrim($url, '/'));

        return $normalized !== '' && collect($targetUrls)->contains(function (string $target) use ($normalized): bool {
            $target = Str::lower(rtrim($target, '/'));

            return $target !== '' && ($normalized === $target || Str::startsWith($normalized, $target.'/'));
        });
    }

    /**
     * @param array<int,string> $competitorTerms
     */
    private function competitorCited(string $url, string $domain, array $competitorTerms): bool
    {
        $haystack = Str::lower($url.' '.$domain);

        return collect($competitorTerms)->contains(fn (string $term): bool => $term !== '' && Str::contains($haystack, $term));
    }

    private function domain(string $url): string
    {
        $candidate = trim($url);
        if ($candidate === '') {
            return '';
        }

        if (! Str::contains($candidate, '://')) {
            $candidate = 'https://'.ltrim($candidate, '/');
        }

        return Str::lower((string) (parse_url($candidate, PHP_URL_HOST) ?: ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function retentionPolicy(string $provider): array
    {
        $provider = Str::lower(trim($provider));
        $default = (array) config('llm_tracking.geo.retention.default', []);
        $providerPolicy = (array) config('llm_tracking.geo.retention.providers.'.$provider, []);

        return array_replace($default, $providerPolicy);
    }

    /**
     * @param array<string,mixed> $retention
     */
    private function answerSummary(string $answer, array $retention): ?string
    {
        if (! (bool) ($retention['store_answer_summary'] ?? true)) {
            return null;
        }

        $limit = max(80, (int) ($retention['max_answer_summary_chars'] ?? 500));

        return Str::limit(preg_replace('/\s+/', ' ', trim($answer)) ?? trim($answer), $limit, '');
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $retention
     * @return array<string,mixed>|null
     */
    private function rawPayload(LlmTrackingQueryRun $run, array $source, array $retention): ?array
    {
        if (! (bool) ($retention['store_raw_payload'] ?? false)) {
            return [
                'source' => $source,
                'retention' => 'raw_answer_omitted',
            ];
        }

        return [
            'source' => $source,
            'parsed_payload' => $run->parsed_payload,
            'answer_json' => $run->answer_json,
        ];
    }

    private function runLevelHash(LlmTrackingQueryRun $run): string
    {
        return hash('sha256', 'run-level|'.$run->id);
    }

    private function answerEngine(string $provider): string
    {
        return match (Str::lower(trim($provider))) {
            'openai' => 'chatgpt',
            'anthropic' => 'claude',
            'gemini' => 'google_ai',
            'mistral' => 'mistral',
            default => 'llm',
        };
    }

    private function emitMovementSignals(LlmTrackingQueryRun $run, PageGeoObservation $current): void
    {
        $query = $run->trackingQuery;
        $workspace = $query?->workspace;
        if (! $query || ! $workspace) {
            return;
        }

        $previous = PageGeoObservation::query()
            ->where('workspace_id', $workspace->id)
            ->where('llm_tracking_query_id', $query->id)
            ->whereNull('cited_url')
            ->where('observed_at', '<', $current->observed_at)
            ->where(function (Builder $builder) use ($run): void {
                $run->provider === null ? $builder->whereNull('provider') : $builder->where('provider', $run->provider);
            })
            ->where(function (Builder $builder) use ($run): void {
                $run->model === null ? $builder->whereNull('model') : $builder->where('model', $run->model);
            })
            ->latest('observed_at')
            ->first();

        if (! $previous) {
            return;
        }

        if (! $previous->client_cited && $current->client_cited) {
            $this->emitSignal($run, $current, 'client_gained_citation', SignalCategory::AI_VISIBILITY->value, SignalType::BRAND_MENTIONED->value, SignalSeverity::INFO->value);
        }

        if ($previous->client_cited && ! $current->client_cited) {
            $this->emitSignal($run, $current, 'client_lost_citation', SignalCategory::RISK->value, SignalType::OWNED_CITATION_MISSING->value, SignalSeverity::HIGH->value);
        }

        if (! $previous->competitors_cited && $current->competitors_cited) {
            $this->emitSignal($run, $current, 'competitor_gained_citation', SignalCategory::COMPETITOR_VISIBILITY->value, SignalType::COMPETITOR_DOMINANCE->value, SignalSeverity::MEDIUM->value);
        }

        if ($previous->client_cited && ! $current->client_cited && $current->competitors_cited) {
            $this->emitSignal($run, $current, 'competitor_displaced_client', SignalCategory::RISK->value, SignalType::RISK_COMPETITOR_PRESSURE->value, SignalSeverity::HIGH->value, [
                'previous_client_cited' => true,
                'current_client_cited' => false,
                'current_competitors_cited' => true,
            ]);
        }

        $previousOwnership = (float) ($previous->topic_ownership_score ?? 0);
        $currentOwnership = (float) ($current->topic_ownership_score ?? 0);
        if ((int) ($query->priority ?? 50) >= 80 && abs($currentOwnership - $previousOwnership) >= 0.2) {
            $this->emitSignal($run, $current, 'topic_ownership_changed', SignalCategory::AI_VISIBILITY->value, SignalType::CONTENT_GAP_SIGNAL->value, SignalSeverity::MEDIUM->value, [
                'previous_topic_ownership_score' => $previousOwnership,
                'current_topic_ownership_score' => $currentOwnership,
            ]);
        }
    }

    private function refreshPageIntelligenceScore(PageGeoObservation $observation): void
    {
        $snapshot = $observation->snapshot ?: $observation->page?->latestSnapshot()->first();
        if (! $snapshot) {
            return;
        }

        $this->intelligenceScoreCalculator->calculate($snapshot);
    }

    /**
     * @param array<string,mixed> $extraMetrics
     */
    private function emitSignal(LlmTrackingQueryRun $run, PageGeoObservation $observation, string $eventKey, string $category, string $type, string $severity, array $extraMetrics = []): void
    {
        $query = $run->trackingQuery;
        $workspace = $query?->workspace;
        if (! $query || ! $workspace) {
            return;
        }

        $this->signalEventIngestor->ingestEvent($workspace, [
            'client_site_id' => $query->client_site_id,
            'category' => $category,
            'type' => $type,
            'severity' => $severity,
            'status' => SignalStatus::NEW->value,
            'topic' => (string) $query->query_text,
            'entity_name' => $query->target_brand ?: $workspace->display_name,
            'entity_key' => 'geo:'.Str::slug((string) ($query->target_brand ?: $workspace->display_name)),
            'signal_strength' => max(25, min(100, (float) $observation->geo_visibility_score)),
            'confidence_score' => max(50, min(100, ((float) ($run->model_confidence_score ?? 0.75)) * 100)),
            'impact_score' => max(35, min(100, ((int) ($query->priority ?? 50)) + 10)),
            'risk_score' => in_array($eventKey, ['client_lost_citation', 'competitor_gained_citation', 'competitor_displaced_client'], true) ? 75 : null,
            'opportunity_score' => $eventKey === 'client_gained_citation' ? 75 : null,
            'observed_at' => $observation->observed_at,
            'evidence' => [[
                'type' => 'page_geo_observation',
                'page_geo_observation_id' => $observation->id,
                'llm_tracking_query_run_id' => $run->id,
                'answer_summary' => $observation->answer_summary,
            ]],
            'metrics' => array_replace([
                'geo_visibility_score' => (float) $observation->geo_visibility_score,
                'topic_ownership_score' => (float) $observation->topic_ownership_score,
                'client_cited' => $observation->client_cited,
                'competitors_cited' => $observation->competitors_cited,
            ], $extraMetrics),
            'metadata' => [
                'source' => 'page_intelligence_geo',
                'event_key' => $eventKey,
                'page_geo_observation_id' => $observation->id,
                'llm_tracking_query_id' => $query->id,
                'llm_tracking_query_run_id' => $run->id,
                'provider' => $run->provider,
                'model' => $run->model,
            ],
            'dedupe_hash' => $this->signalEventIngestor->dedupeHash([
                'workspace_id' => $workspace->id,
                'source' => 'page_intelligence_geo',
                'event_key' => $eventKey,
                'run_id' => $run->id,
            ]),
        ], $query->site);
    }
}
