<?php

namespace App\View\Presenters;

use App\Models\LlmTrackingAggregate;
use App\Models\LlmAuthorityEntityCandidate;
use App\Models\LlmAuthorityLearning;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TrackingQueryDetailPresenter
{
    /**
     * @param  Collection<int,LlmTrackingQueryRun>  $runs
     * @param  Collection<int,LlmTrackingAggregate>  $weeklyAggregates
     * @return array<string,mixed>
     */
    public static function make(LlmTrackingQuery $query, Collection $runs, Collection $weeklyAggregates): array
    {
        /** @var LlmTrackingQueryRun|null $latestRun */
        $latestRun = $runs->first();
        /** @var LlmTrackingQueryRun|null $previousRun */
        $previousRun = $runs->skip(1)->first();
        /** @var LlmTrackingQueryRun|null $baselineRun */
        $baselineRun = $runs->last();

        $brandShare = self::floatOrNull(data_get($latestRun, 'share_of_voice_snapshot.share_brand'));
        $sourceRows = self::presentSources($query, $latestRun);
        $authorityCandidates = self::presentAuthorityCandidates($query);
        $competitorRows = self::presentCompetitors($latestRun, $authorityCandidates);
        $historyRows = self::presentHistoryRows($runs);
        $historySummary = self::historySummary($historyRows, $latestRun, $previousRun, $baselineRun);
        $queryContext = self::presentQueryContext($query);
        $recommendations = self::recommendedActions($latestRun, $queryContext, $competitorRows, $sourceRows);
        $findings = self::findings($latestRun, $queryContext, $competitorRows, $sourceRows, $recommendations);

        return [
            'header' => [
                'title' => (string) $query->name,
                'description' => self::queryDescription($query),
                'status' => self::headerStatus($query, $latestRun),
                'latest_run_label' => $latestRun?->run_at ? self::formatTimestamp($latestRun->run_at) : 'No successful runs yet',
                'provider_label' => self::providerLabel($latestRun),
                'model_label' => trim((string) ($latestRun?->model ?? 'No model yet')),
            ],
            'summary_metrics' => [
                [
                    'label' => 'Visibility score',
                    'value' => self::formatPercent($latestRun?->ai_visibility_score),
                    'context' => self::deltaLabel($latestRun?->ai_visibility_score, $previousRun?->ai_visibility_score, 'since previous run'),
                    'helper' => self::metricHelper($latestRun?->ai_visibility_score, 'Higher means stronger brand visibility in the answer.'),
                    'tone' => self::scoreTone($latestRun?->ai_visibility_score),
                ],
                [
                    'label' => 'Mention rate',
                    'value' => self::formatPercent(self::mentionRate($runs), 1, true),
                    'context' => $runs->isNotEmpty() ? sprintf('%d of %d runs mention the brand', $runs->where('brand_mentioned', true)->count(), $runs->count()) : 'No run history yet',
                    'helper' => 'Tracks how often the brand appears across recent runs.',
                    'tone' => self::rateTone(self::mentionRate($runs)),
                ],
                [
                    'label' => 'Average rank / placement',
                    'value' => self::positionLabel($latestRun?->position_score),
                    'context' => self::deltaLabel($latestRun?->position_score, $previousRun?->position_score, 'vs previous placement'),
                    'helper' => $latestRun ? self::positionExplanation($latestRun) : 'No placement data yet.',
                    'tone' => self::scoreTone($latestRun?->position_score),
                ],
                [
                    'label' => 'Sentiment',
                    'value' => self::contextLabel($latestRun?->context_label ?? $latestRun?->sentiment_label),
                    'context' => self::firstNonEmpty([
                        self::limitText((string) ($latestRun?->first_mention_context ?? ''), 92),
                        'No context explanation yet',
                    ]),
                    'helper' => 'Brand mention context classified from answer snippets and first mention context.',
                    'tone' => self::contextTone($latestRun?->context_label ?? $latestRun?->sentiment_label),
                ],
                [
                    'label' => 'Competitor count',
                    'value' => $latestRun ? (string) count(array_filter((array) ($latestRun->competitor_hits ?? []), fn ($hit) => (int) ($hit['count'] ?? 0) > 0)) : '-',
                    'context' => $queryContext['competitor_terms_count'] > 0
                        ? sprintf('%d tracked competitor %s', $queryContext['competitor_terms_count'], Str::plural('term', $queryContext['competitor_terms_count']))
                        : 'No competitor list configured',
                    'helper' => $competitorRows['summary'],
                    'tone' => $competitorRows['tone'],
                ],
                [
                    'label' => 'Source diversity',
                    'value' => $latestRun ? (string) $sourceRows['domain_count'] : '-',
                    'context' => $latestRun ? sprintf('%d source %s across %d %s', $sourceRows['row_count'], Str::plural('citation', $sourceRows['row_count']), $sourceRows['type_count'], Str::plural('type', $sourceRows['type_count'])) : 'No source evidence yet',
                    'helper' => $sourceRows['summary'],
                    'tone' => $sourceRows['tone'],
                ],
                [
                    'label' => 'Citation count',
                    'value' => $latestRun ? (string) $sourceRows['row_count'] : '-',
                    'context' => self::firstNonEmpty([
                        $sourceRows['owned_count'] > 0 ? sprintf('%d owned source %s cited', $sourceRows['owned_count'], Str::plural('source', $sourceRows['owned_count'])) : null,
                        $latestRun?->urls_cited ? 'Citations present in the answer' : 'No citations detected',
                    ]),
                    'helper' => 'Counts unique cited or extracted source URLs in the latest answer.',
                    'tone' => $sourceRows['owned_count'] > 0 ? 'emerald' : 'amber',
                ],
                [
                    'label' => 'Presence trend',
                    'value' => self::trendLabel($latestRun?->ai_visibility_score, $previousRun?->ai_visibility_score),
                    'context' => $historySummary['trend_context'],
                    'helper' => 'Change in visibility score relative to the previous successful run.',
                    'tone' => self::deltaTone($latestRun?->ai_visibility_score, $previousRun?->ai_visibility_score),
                ],
            ],
            'tabs' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'competitors', 'label' => 'Competitors'],
                ['id' => 'sources', 'label' => 'Sources'],
                ['id' => 'findings', 'label' => 'Findings'],
                ['id' => 'history', 'label' => 'History'],
                ['id' => 'raw', 'label' => 'Raw response'],
            ],
            'query_context' => $queryContext,
            'overview' => [
                'executive_summary' => self::executiveSummary($latestRun, $previousRun, $competitorRows, $sourceRows, $recommendations),
                'brand_presence' => self::brandPresence($latestRun, $brandShare, $sourceRows),
                'score_factors' => self::scoreFactors($latestRun),
                'comparison' => [
                    'current_vs_previous' => self::compareRun('Previous run', $latestRun, $previousRun),
                    'current_vs_baseline' => self::compareRun('Baseline', $latestRun, $baselineRun),
                ],
            ],
            'competitors' => $competitorRows,
            'authority_candidates' => $authorityCandidates,
            'authority_learnings' => self::presentAuthorityLearnings($query),
            'sources' => $sourceRows,
            'findings' => $findings,
            'recommended_actions' => $recommendations,
            'history' => [
                'summary' => $historySummary,
                'rows' => $historyRows,
                'trend_rows' => self::presentAggregateRows($weeklyAggregates),
            ],
            'raw' => self::rawPayloads($latestRun),
        ];
    }

    private static function queryDescription(LlmTrackingQuery $query): string
    {
        $parts = array_filter([
            $query->querySet?->name ? 'Set: ' . $query->querySet->name : null,
            $query->target_brand ? 'Brand: ' . $query->target_brand : null,
            $query->locale ? 'Locale: ' . $query->locale : null,
            $query->frequency ? 'Cadence: ' . Str::headline($query->frequency) : null,
        ]);

        return implode(' · ', $parts) !== ''
            ? implode(' · ', $parts)
            : 'LLM visibility tracking query';
    }

    /**
     * @return array{label:string,tone:string,icon:string}
     */
    private static function headerStatus(LlmTrackingQuery $query, ?LlmTrackingQueryRun $latestRun): array
    {
        if (! $query->is_active) {
            return ['label' => 'Inactive', 'tone' => 'slate', 'icon' => 'pause-circle'];
        }

        return match ((string) ($latestRun?->status ?? 'draft')) {
            'succeeded' => ['label' => 'Ready', 'tone' => 'emerald', 'icon' => 'badge-check'],
            'failed' => ['label' => 'Failed', 'tone' => 'rose', 'icon' => 'alert-triangle'],
            'running' => ['label' => 'Running', 'tone' => 'blue', 'icon' => 'loader-circle'],
            default => ['label' => 'Draft', 'tone' => 'amber', 'icon' => 'sparkles'],
        };
    }

    private static function providerLabel(?LlmTrackingQueryRun $latestRun): string
    {
        if (! $latestRun) {
            return 'No provider yet';
        }

        return trim((string) ($latestRun->provider ?: 'Unknown provider'));
    }

    /**
     * @return array<string,mixed>
     */
    private static function presentQueryContext(LlmTrackingQuery $query): array
    {
        $primary = [
            ['label' => 'Goal', 'value' => self::goalLabel($query)],
            ['label' => 'Target brand', 'value' => trim((string) ($query->target_brand ?? '')) ?: 'Not set'],
            ['label' => 'Locale', 'value' => trim((string) ($query->locale ?? '')) ?: 'Not set'],
            ['label' => 'Cadence', 'value' => Str::headline((string) ($query->frequency ?? 'daily'))],
        ];

        $secondary = [
            ['label' => 'Query set', 'value' => trim((string) ($query->querySet?->name ?? '')) ?: 'No query set'],
            ['label' => 'Priority', 'value' => (string) ($query->priority ?? 50)],
            ['label' => 'Domain', 'value' => trim((string) ($query->target_domain ?? '')) ?: 'No domain'],
            ['label' => 'Status', 'value' => $query->is_active ? 'Active' : 'Inactive'],
        ];

        return [
            'summary' => self::limitText((string) $query->query_text, 140),
            'query_text' => (string) $query->query_text,
            'primary_fields' => $primary,
            'secondary_fields' => $secondary,
            'lists' => [
                ['label' => 'Brand terms', 'items' => self::normalizeStrings((array) ($query->brand_terms ?? []))],
                ['label' => 'Competitor terms', 'items' => self::normalizeStrings((array) ($query->competitor_terms ?? []))],
                ['label' => 'Target URLs', 'items' => self::normalizeStrings((array) ($query->target_urls ?? []))],
                ['label' => 'Tags', 'items' => self::normalizeStrings((array) ($query->tags ?? []))],
            ],
            'goal_label' => self::goalLabel($query),
            'competitor_terms_count' => count(self::normalizeStrings((array) ($query->competitor_terms ?? []))),
        ];
    }

    private static function goalLabel(LlmTrackingQuery $query): string
    {
        $setName = Str::lower((string) ($query->querySet?->name ?? ''));
        $queryText = Str::lower((string) $query->query_text);

        return match (true) {
            Str::contains($setName, 'geo'), Str::contains($queryText, 'geo') => 'GEO visibility',
            Str::contains($setName, 'brand'), Str::contains($queryText, 'brand') => 'Brand monitoring',
            Str::contains($setName, 'seo'), Str::contains($queryText, 'seo') => 'SEO visibility',
            default => 'AI visibility tracking',
        };
    }

    /**
     * @return array<int,string>
     */
    private static function executiveSummary(
        ?LlmTrackingQueryRun $latestRun,
        ?LlmTrackingQueryRun $previousRun,
        array $competitors,
        array $sources,
        array $recommendations
    ): array {
        if (! $latestRun) {
            return [
                'No successful run yet. Start with a fresh run to generate visibility analysis.',
                'The first useful checkpoint is whether the brand appears at all in the answer.',
                'Once runs exist, this panel will summarize what changed and what to do next.',
            ];
        }

        $bullets = [
            $latestRun->brand_mentioned
                ? 'The brand is visible in the latest answer.'
                : 'The brand is not visible in the latest answer.',
            self::positionExplanation($latestRun),
            $competitors['summary'],
            $sources['summary'],
            'Sentiment is ' . Str::lower(self::contextLabel($latestRun->context_label ?? $latestRun->sentiment_label)) . '.',
            self::firstActionSentence($recommendations),
        ];

        $delta = self::deltaValue($latestRun->ai_visibility_score, $previousRun?->ai_visibility_score);
        if ($delta !== null) {
            array_splice($bullets, 1, 0, [
                $delta > 0
                    ? 'Visibility is improving versus the previous run.'
                    : ($delta < 0 ? 'Visibility is down versus the previous run.' : 'Visibility is flat versus the previous run.'),
            ]);
        }

        return array_slice(array_values(array_unique(array_filter($bullets))), 0, 6);
    }

    /**
     * @return array<string,mixed>
     */
    private static function brandPresence(?LlmTrackingQueryRun $latestRun, ?float $brandShare, array $sources): array
    {
        if (! $latestRun) {
            return [
                'rows' => [
                    ['label' => 'Mentioned', 'value' => 'No run yet', 'tone' => 'slate'],
                ],
            ];
        }

        return [
            'rows' => [
                ['label' => 'Mentioned', 'value' => $latestRun->brand_mentioned ? 'Yes' : 'No', 'tone' => $latestRun->brand_mentioned ? 'emerald' : 'rose'],
                ['label' => 'Relative position', 'value' => self::positionLabel($latestRun->position_score), 'tone' => self::scoreTone($latestRun->position_score)],
                ['label' => 'Prominence', 'value' => $brandShare !== null ? self::formatPercent($brandShare) . ' share of mentions' : 'No share data', 'tone' => self::scoreTone($brandShare)],
                ['label' => 'Mention mode', 'value' => $latestRun->brand_mentioned ? 'Direct brand mention' : 'Absent from answer', 'tone' => $latestRun->brand_mentioned ? 'emerald' : 'rose'],
                ['label' => 'Context', 'value' => self::contextLabel($latestRun->context_label ?? $latestRun->sentiment_label), 'tone' => self::contextTone($latestRun->context_label ?? $latestRun->sentiment_label)],
                ['label' => 'Citation footprint', 'value' => $sources['owned_count'] > 0 ? 'Owned source cited' : ($latestRun->urls_cited ? 'Third-party citations only' : 'No citations detected'), 'tone' => $sources['owned_count'] > 0 ? 'emerald' : ($latestRun->urls_cited ? 'amber' : 'rose')],
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function scoreFactors(?LlmTrackingQueryRun $latestRun): array
    {
        $breakdown = (array) ($latestRun?->visibility_breakdown ?? []);
        $explainability = (array) data_get($breakdown, 'explainability', []);
        $weights = (array) data_get($breakdown, 'weights', []);

        return collect([
            ['label' => 'Owned visibility', 'weight_key' => 'owned_visibility', 'score' => data_get($breakdown, 'subscores_100.owned_visibility'), 'text' => $explainability['owned_visibility'] ?? ''],
            ['label' => 'Earned visibility', 'weight_key' => 'earned_visibility', 'score' => data_get($breakdown, 'subscores_100.earned_visibility'), 'text' => $explainability['earned_visibility'] ?? ''],
            ['label' => 'Competitor pressure', 'weight_key' => 'competitor_pressure', 'score' => data_get($breakdown, 'subscores_100.competitor_pressure'), 'text' => $explainability['competitor_pressure'] ?? ''],
            ['label' => 'Citation diversity', 'weight_key' => 'citation_diversity', 'score' => data_get($breakdown, 'subscores_100.citation_diversity'), 'text' => $explainability['citation_diversity'] ?? ''],
            ['label' => 'Model confidence', 'weight_key' => 'model_confidence', 'score' => data_get($breakdown, 'subscores_100.model_confidence'), 'text' => $explainability['model_confidence'] ?? ''],
            ['label' => 'Real-world gap', 'weight_key' => 'real_world_gap', 'score' => data_get($breakdown, 'subscores_100.real_world_gap'), 'text' => $explainability['real_world_gap'] ?? ''],
        ])
            ->map(function (array $item) use ($weights): array {
                return [
                    'label' => $item['label'],
                    'score' => is_numeric($item['score']) ? number_format((float) $item['score'], 1) : '-',
                    'weight' => array_key_exists((string) $item['weight_key'], $weights)
                        ? number_format(((float) $weights[(string) $item['weight_key']] * 100), 0) . '% weight'
                        : null,
                    'text' => trim((string) $item['text']),
                ];
            })
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private static function compareRun(string $label, ?LlmTrackingQueryRun $current, ?LlmTrackingQueryRun $comparison): array
    {
        if (! $current || ! $comparison) {
            return [
                'label' => $label,
                'available' => false,
                'rows' => [],
            ];
        }

        $rows = [
            self::comparisonRow('Visibility score', $current->ai_visibility_score, $comparison->ai_visibility_score),
            self::comparisonRow('Position score', $current->position_score, $comparison->position_score),
            self::comparisonRow('Citation score', $current->citation_score, $comparison->citation_score),
            self::comparisonRow('Competitor pressure', $current->competitor_share_score ?? $current->competitive_score, $comparison->competitor_share_score ?? $comparison->competitive_score),
        ];

        return [
            'label' => $label,
            'available' => true,
            'run_label' => $comparison->run_at ? self::formatTimestamp($comparison->run_at) : null,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function comparisonRow(string $label, mixed $current, mixed $comparison): array
    {
        return [
            'label' => $label,
            'current' => self::formatPercent(self::floatOrNull($current)),
            'comparison' => self::formatPercent(self::floatOrNull($comparison)),
            'delta' => self::deltaLabel(self::floatOrNull($current), self::floatOrNull($comparison), null),
            'delta_tone' => self::deltaTone(self::floatOrNull($current), self::floatOrNull($comparison)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function presentCompetitors(?LlmTrackingQueryRun $latestRun, array $authorityCandidates = []): array
    {
        if (! $latestRun) {
            return [
                'rows' => [],
                'candidate_rows' => $authorityCandidates,
                'summary' => 'No competitor analysis yet.',
                'conclusion' => 'Run the query to compare brand visibility against tracked competitors.',
                'tone' => 'slate',
            ];
        }

        $brandCount = (int) collect((array) ($latestRun->brand_hits ?? []))->sum(fn ($hit): int => (int) ($hit['count'] ?? 0));
        $brandPosition = self::floatOrNull($latestRun->position_score) ?? 0.0;
        $totalMentions = $brandCount + (int) collect((array) ($latestRun->competitor_hits ?? []))->sum(fn ($hit): int => (int) ($hit['count'] ?? 0));

        $rows = collect((array) ($latestRun->entity_presence ?? []))
            ->filter(fn ($entry): bool => (string) ($entry['type'] ?? '') === 'competitor')
            ->map(function (array $entry) use ($brandCount, $brandPosition, $totalMentions): array {
                $mentionCount = (int) ($entry['count'] ?? 0);
                $positionScore = self::floatOrNull($entry['position_score']) ?? 0.0;
                $share = $totalMentions > 0 ? round($mentionCount / $totalMentions, 4) : null;

                return [
                    'name' => trim((string) ($entry['term'] ?? 'Unknown competitor')),
                    'mentioned' => ! empty($entry['present']),
                    'mentions' => $mentionCount,
                    'share' => $share,
                    'position' => self::positionLabel($positionScore),
                    'context' => self::limitText((string) collect((array) ($entry['snippet_context'] ?? []))->first(), 120),
                    'advantage' => match (true) {
                        ! empty($entry['present']) && $positionScore > $brandPosition => 'Ahead of brand',
                        ! empty($entry['present']) && $mentionCount > $brandCount => 'Louder than brand',
                        ! empty($entry['present']) => 'Present but behind brand',
                        default => 'Not visible',
                    },
                    'tone' => match (true) {
                        ! empty($entry['present']) && ($positionScore > $brandPosition || $mentionCount > $brandCount) => 'rose',
                        ! empty($entry['present']) => 'amber',
                        default => 'emerald',
                    },
                ];
            })
            ->sortByDesc(fn (array $row): array => [$row['mentions'], $row['mentioned'] ? 1 : 0])
            ->values()
            ->all();

        $visible = collect($rows)->where('mentioned', true);
        $summary = $visible->isEmpty()
            ? 'No tracked competitors appeared in the latest answer.'
            : sprintf('%d competitor %s appeared in the latest answer.', $visible->count(), Str::plural('name', $visible->count()));

        $conclusion = match (true) {
            $visible->isEmpty() => 'Competitor pressure is low in this answer. The next step is increasing branded authority and owned citations.',
            $visible->contains(fn (array $row): bool => $row['advantage'] === 'Ahead of brand') => 'Competitors outperform this brand on authority and category association.',
            $visible->contains(fn (array $row): bool => $row['advantage'] === 'Louder than brand') => 'Competitors consume more of the answer than the brand does.',
            default => 'Competitors are present, but the brand still has room to increase prominence and citation depth.',
        };

        return [
            'rows' => $rows,
            'candidate_rows' => $authorityCandidates,
            'summary' => $summary,
            'conclusion' => $conclusion,
            'tone' => $visible->contains(fn (array $row): bool => in_array($row['advantage'], ['Ahead of brand', 'Louder than brand'], true)) ? 'rose' : ($visible->isNotEmpty() ? 'amber' : 'emerald'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function presentAuthorityCandidates(LlmTrackingQuery $query): array
    {
        return LlmAuthorityEntityCandidate::query()
            ->where('client_site_id', $query->client_site_id)
            ->where(function ($builder) use ($query): void {
                $builder->where('llm_tracking_query_id', $query->id)
                    ->orWhereNull('llm_tracking_query_id');
            })
            ->whereIn('status', ['candidate', 'accepted'])
            ->orderByDesc('confidence_score')
            ->orderByDesc('mention_count')
            ->limit(12)
            ->get()
            ->map(fn (LlmAuthorityEntityCandidate $candidate): array => [
                'id' => $candidate->id,
                'brand_name' => $candidate->brand_name,
                'category' => str_replace('_', ' ', (string) $candidate->entity_category),
                'mention_count' => (int) $candidate->mention_count,
                'latest_rank' => $candidate->latest_rank,
                'average_rank' => $candidate->average_rank,
                'providers' => collect((array) $candidate->provider_breakdown)->keys()->values()->all(),
                'source_urls' => (array) $candidate->source_urls,
                'reason' => (string) data_get($candidate->evidence, 'latest_reason', 'Detected as a high-performing entity in AI answers.'),
                'status' => (string) $candidate->status,
            ])
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function presentAuthorityLearnings(LlmTrackingQuery $query): array
    {
        return LlmAuthorityLearning::query()
            ->where('client_site_id', $query->client_site_id)
            ->where(function ($builder) use ($query): void {
                $builder->where('llm_tracking_query_id', $query->id)
                    ->orWhereNull('llm_tracking_query_id');
            })
            ->where('status', 'active')
            ->orderBy('priority')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (LlmAuthorityLearning $learning): array => [
                'title' => $learning->title,
                'type' => str_replace('_', ' ', (string) $learning->learning_type),
                'summary' => $learning->summary,
                'recommended_action' => $learning->recommended_action,
                'provider' => $learning->provider,
                'priority' => $learning->priority,
            ])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private static function presentSources(LlmTrackingQuery $query, ?LlmTrackingQueryRun $latestRun): array
    {
        if (! $latestRun) {
            return [
                'rows' => [],
                'citations' => [],
                'summary' => 'No source evidence yet.',
                'domain_count' => 0,
                'type_count' => 0,
                'row_count' => 0,
                'owned_count' => 0,
                'tone' => 'slate',
            ];
        }

        $brandTerms = collect((array) ($query->brand_terms ?? []))
            ->map(fn ($term): string => Str::lower(trim((string) $term)))
            ->filter()
            ->values();

        $targetDomain = Str::lower(trim((string) ($query->target_domain ?? '')));
        $rowCount = collect((array) ($latestRun->sources ?? []))->count();

        $rows = collect((array) ($latestRun->sources ?? []))
            ->map(function (array $source) use ($targetDomain, $brandTerms): array {
                $domain = Str::lower(trim((string) ($source['domain'] ?? '')));
                $type = trim((string) ($source['type'] ?? 'website')) ?: 'website';
                $url = trim((string) ($source['url'] ?? ''));
                $position = (int) ($source['position'] ?? 0);
                $owned = $targetDomain !== '' && ($domain === $targetDomain || Str::endsWith($domain, '.' . $targetDomain));
                $branded = $owned || $brandTerms->contains(fn (string $term): bool => $term !== '' && (Str::contains($domain, Str::slug($term, '')) || Str::contains($url, Str::slug($term, '-'))));
                $classification = $owned
                    ? 'Owned'
                    : (in_array($type, ['news', 'blog', 'wikipedia', 'forum', 'docs'], true) ? 'Earned' : 'Third-party');

                return [
                    'domain' => $domain !== '' ? $domain : 'Unknown domain',
                    'url' => $url,
                    'type' => Str::headline($type),
                    'classification' => $classification,
                    'role' => match (true) {
                        $position > 0 && $position <= 160 => 'Lead evidence',
                        $position > 0 && $position <= 420 => 'Supporting evidence',
                        default => 'Background reference',
                    },
                    'branded' => $branded ? 'Branded' : 'Non-branded',
                    'position' => $position > 0 ? (string) $position : 'n/a',
                    'tone' => $owned ? 'emerald' : ($classification === 'Earned' ? 'amber' : 'slate'),
                ];
            })
            ->sortBy(fn (array $row): array => [$row['classification'] === 'Owned' ? 0 : 1, $row['position'] === 'n/a' ? 999999 : (int) $row['position']])
            ->values();

        $snippetCandidates = collect([
            ...collect((array) ($latestRun->brand_hits ?? []))->pluck('context_snippets')->flatten()->all(),
            ...collect((array) ($latestRun->competitor_hits ?? []))->pluck('context_snippets')->flatten()->all(),
            ...collect((array) ($latestRun->entity_presence ?? []))->pluck('snippet_context')->flatten()->all(),
        ])
            ->map(fn ($snippet): string => trim((string) $snippet))
            ->filter()
            ->unique()
            ->values();

        $citations = $rows
            ->values()
            ->map(function (array $row, int $index) use ($snippetCandidates): array {
                $excerpt = (string) ($snippetCandidates->get($index) ?? $snippetCandidates->first() ?? '');

                return [
                    'domain' => $row['domain'],
                    'url' => $row['url'],
                    'type' => $row['type'],
                    'excerpt' => self::limitText($excerpt !== '' ? $excerpt : 'No excerpt preview captured for this source.', 180),
                    'why_it_matters' => match ($row['classification']) {
                        'Owned' => 'Owned evidence strengthens authority and lets the model cite the brand directly.',
                        'Earned' => 'Earned mentions shape category association and third-party validation.',
                        default => 'Third-party references still influence the answer even without direct brand control.',
                    },
                ];
            })
            ->all();

        $ownedCount = $rows->where('classification', 'Owned')->count();
        $typeCount = $rows->pluck('type')->unique()->count();
        $domainCount = $rows->pluck('domain')->unique()->count();

        return [
            'rows' => $rows->all(),
            'citations' => $citations,
            'summary' => $ownedCount > 0
                ? sprintf('Owned citations are present, but %d non-owned %s still influence the answer.', max(0, $rowCount - $ownedCount), Str::plural('source', max(0, $rowCount - $ownedCount)))
                : ($rowCount > 0 ? 'Source evidence is present, but it is dominated by non-owned domains.' : 'No source evidence detected.'),
            'domain_count' => $domainCount,
            'type_count' => $typeCount,
            'row_count' => $rowCount,
            'owned_count' => $ownedCount,
            'tone' => $ownedCount > 0 ? 'emerald' : ($rowCount > 0 ? 'amber' : 'rose'),
        ];
    }

    /**
     * @param  array<string,mixed>  $queryContext
     * @param  array<string,mixed>  $competitors
     * @param  array<string,mixed>  $sources
     * @return array<string,array<int,string>>
     */
    private static function recommendedActions(?LlmTrackingQueryRun $latestRun, array $queryContext, array $competitors, array $sources): array
    {
        $suggestions = collect((array) ($latestRun?->suggestions ?? []));

        $quickWins = collect()
            ->push(! ($latestRun?->brand_mentioned ?? false) ? 'Rewrite owned pages to mirror the exact query wording and answer the question directly.' : null)
            ->push($sources['owned_count'] === 0 ? 'Add owned citations or entity-rich pages that can be cited directly by the model.' : null)
            ->merge($suggestions->flatMap(fn (array $suggestion): array => (array) ($suggestion['seo_geo_improvements'] ?? [])))
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();

        $strategic = collect()
            ->merge($suggestions->flatMap(function (array $suggestion): array {
                $items = [];
                foreach ((array) ($suggestion['landing_pages'] ?? []) as $page) {
                    $title = trim((string) ($page['title'] ?? ''));
                    if ($title !== '') {
                        $items[] = 'Create or expand: ' . $title;
                    }
                }

                foreach ((array) ($suggestion['content_topics'] ?? []) as $topic) {
                    $topic = trim((string) $topic);
                    if ($topic !== '') {
                        $items[] = 'Build authority around: ' . $topic;
                    }
                }

                return $items;
            }))
            ->push(collect($competitors['rows'] ?? [])->contains(fn (array $row): bool => ($row['advantage'] ?? '') === 'Ahead of brand')
                ? 'Develop supporting comparison and category pages so competitors stop owning the early answer blocks.'
                : null)
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();

        $measurement = collect()
            ->push('Compare the current run with the previous run after each content or citation change.')
            ->push($queryContext['competitor_terms_count'] < 3 ? 'Add more tracked competitors to see whether authority is genuinely improving.' : null)
            ->push('Create a variant query that isolates SEO, GEO, and brand-monitoring intent separately.')
            ->push('Track a query version for a different locale or device when those audiences matter.')
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();

        return [
            'quick_wins' => $quickWins,
            'strategic' => $strategic,
            'measurement' => $measurement,
        ];
    }

    /**
     * @param  array<string,mixed>  $queryContext
     * @param  array<string,mixed>  $competitors
     * @param  array<string,mixed>  $sources
     * @param  array<string,array<int,string>>  $recommendations
     * @return array<int,array<string,mixed>>
     */
    private static function findings(?LlmTrackingQueryRun $latestRun, array $queryContext, array $competitors, array $sources, array $recommendations): array
    {
        $strengths = collect();
        $weaknesses = collect();
        $missingAssociations = collect();
        $signals = collect();
        $opportunities = collect($recommendations['quick_wins'] ?? []);
        $risks = collect();

        if (! $latestRun) {
            $weaknesses->push('No successful run exists yet.');
        } else {
            if ($latestRun->brand_mentioned) {
                $strengths->push('The brand is mentioned in the answer.');
                $signals->push('Brand presence is confirmed for this query.');
            } else {
                $weaknesses->push('The brand is absent from the answer.');
                $missingAssociations->push('Category association is weak enough that the model does not surface the brand.');
            }

            if (($latestRun->position_score ?? 0) >= 0.75) {
                $strengths->push('The first brand mention appears early in the answer.');
            } else {
                $weaknesses->push('The first brand mention is late or buried.');
            }

            if (($latestRun->citation_score ?? 0) >= 0.7) {
                $strengths->push('Citation support exists for the answer.');
            } else {
                $weaknesses->push('Citation support is weak or non-owned.');
            }

            $context = self::contextLabel($latestRun->context_label ?? $latestRun->sentiment_label);
            $signals->push('Context classification: ' . $context . '.');
            if (Str::lower($context) === 'negative') {
                $risks->push('Negative context can anchor the brand to weak or critical sentiment.');
            }
        }

        if (($sources['owned_count'] ?? 0) === 0) {
            $missingAssociations->push('No owned domain is cited in the answer.');
            $risks->push('Third-party domains control the evidence chain.');
        } else {
            $signals->push('Owned sources are part of the citation footprint.');
        }

        if (collect($competitors['rows'] ?? [])->contains(fn (array $row): bool => in_array(($row['advantage'] ?? ''), ['Ahead of brand', 'Louder than brand'], true))) {
            $weaknesses->push('One or more competitors outrank or out-mention the brand.');
            $risks->push('Competitors can become the default category reference for this query.');
        }

        foreach ((array) data_get($latestRun, 'entity_presence', []) as $entry) {
            if (($entry['type'] ?? '') === 'brand' && empty($entry['present'])) {
                $missingAssociations->push('Missing brand signal: ' . (string) ($entry['term'] ?? 'brand term'));
            }
        }

        return [
            ['title' => 'Strengths', 'tone' => 'emerald', 'items' => $strengths->filter()->unique()->take(6)->values()->all()],
            ['title' => 'Weaknesses', 'tone' => 'rose', 'items' => $weaknesses->filter()->unique()->take(6)->values()->all()],
            ['title' => 'Missing associations', 'tone' => 'amber', 'items' => $missingAssociations->filter()->unique()->take(6)->values()->all()],
            ['title' => 'Brand signals detected', 'tone' => 'blue', 'items' => $signals->filter()->unique()->take(6)->values()->all()],
            ['title' => 'Opportunities', 'tone' => 'emerald', 'items' => $opportunities->filter()->unique()->take(6)->values()->all()],
            ['title' => 'Risks', 'tone' => 'rose', 'items' => $risks->filter()->unique()->take(6)->values()->all()],
        ];
    }

    /**
     * @param  Collection<int,LlmTrackingQueryRun>  $runs
     * @return array<int,array<string,mixed>>
     */
    private static function presentHistoryRows(Collection $runs): array
    {
        return $runs
            ->values()
            ->map(function (LlmTrackingQueryRun $run, int $index) use ($runs): array {
                /** @var LlmTrackingQueryRun|null $previous */
                $previous = $runs->get($index + 1);

                return [
                    'run_at' => $run->run_at ? self::formatTimestamp($run->run_at) : 'Unknown',
                    'provider' => trim((string) ($run->provider ?: '-')),
                    'model' => trim((string) ($run->model ?: '-')),
                    'visibility' => self::formatPercent($run->ai_visibility_score),
                    'mention_rate' => $run->brand_mentioned ? 'Present' : 'Missing',
                    'sentiment' => self::contextLabel($run->context_label ?? $run->sentiment_label),
                    'position' => self::positionLabel($run->position_score),
                    'delta' => self::deltaLabel($run->ai_visibility_score, $previous?->ai_visibility_score, 'vs previous'),
                    'delta_tone' => self::deltaTone($run->ai_visibility_score, $previous?->ai_visibility_score),
                    'is_cached' => (bool) $run->is_cached,
                    'error_message' => trim((string) ($run->error_message ?? '')),
                ];
            })
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private static function historySummary(array $rows, ?LlmTrackingQueryRun $latestRun, ?LlmTrackingQueryRun $previousRun, ?LlmTrackingQueryRun $baselineRun): array
    {
        $trendContext = $latestRun && $previousRun
            ? self::deltaLabel($latestRun->ai_visibility_score, $previousRun->ai_visibility_score, 'vs previous run')
            : 'Run at least twice to compare changes over time.';

        return [
            'current_vs_previous' => self::compareRun('Previous run', $latestRun, $previousRun),
            'current_vs_baseline' => self::compareRun('Baseline', $latestRun, $baselineRun),
            'trend_context' => $trendContext,
            'run_count' => count($rows),
        ];
    }

    /**
     * @param  Collection<int,LlmTrackingAggregate>  $aggregates
     * @return array<int,array<string,mixed>>
     */
    private static function presentAggregateRows(Collection $aggregates): array
    {
        return $aggregates
            ->map(function (LlmTrackingAggregate $aggregate): array {
                $metrics = (array) ($aggregate->metrics ?? []);

                return [
                    'period_start' => optional($aggregate->period_start)->format('Y-m-d') ?: '-',
                    'visibility' => self::formatPercent(self::floatOrNull(data_get($metrics, 'avg_ai_visibility_score'))),
                    'mention_rate' => self::formatPercent(self::floatOrNull(data_get($metrics, 'presence_rate')), 1, true),
                    'citation_rate' => self::formatPercent(self::floatOrNull(data_get($metrics, 'citation_rate')), 1, true),
                    'positive_context' => self::formatPercent(self::floatOrNull(data_get($metrics, 'positive_context_rate')), 1, true),
                    'position' => self::formatPercent(self::floatOrNull(data_get($metrics, 'average_position_score'))),
                    'run_count' => (int) data_get($metrics, 'run_count', 0),
                ];
            })
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private static function rawPayloads(?LlmTrackingQueryRun $latestRun): array
    {
        return [
            'available' => $latestRun !== null,
            'answer_text' => (string) ($latestRun?->answer_text ?? ''),
            'normalized_response' => (string) ($latestRun?->normalized_response ?? ''),
            'raw_response' => self::prettyJsonString((string) ($latestRun?->raw_response ?? '')),
            'parsed_payload' => self::prettyJson($latestRun?->parsed_payload),
            'answer_json' => self::prettyJson($latestRun?->answer_json),
        ];
    }

    private static function prettyJson(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }

    private static function prettyJsonString(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return self::prettyJson($decoded);
        }

        return $trimmed;
    }

    /**
     * @param  array<int|string>  $values
     * @return array<int,string>
     */
    private static function normalizeStrings(array $values): array
    {
        return collect($values)
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    private static function mentionRate(Collection $runs): ?float
    {
        if ($runs->isEmpty()) {
            return null;
        }

        return round($runs->where('brand_mentioned', true)->count() / max(1, $runs->count()), 4);
    }

    private static function positionLabel(mixed $score): string
    {
        $score = self::floatOrNull($score);

        return match (true) {
            $score === null => 'Not ranked',
            $score >= 1.0 => 'Primary mention',
            $score >= 0.75 => 'Early mention',
            $score >= 0.5 => 'Visible but later',
            $score > 0 => 'Buried mention',
            default => 'Not ranked',
        };
    }

    private static function positionExplanation(LlmTrackingQueryRun $run): string
    {
        return match (true) {
            ($run->position_score ?? 0) >= 1.0 => 'The brand lands in the first answer block.',
            ($run->position_score ?? 0) >= 0.75 => 'The brand appears early, but not as the opening answer.',
            ($run->position_score ?? 0) >= 0.5 => 'The brand appears later in the answer than it should.',
            ($run->position_score ?? 0) > 0 => 'The brand is buried near the bottom of the answer.',
            default => 'No brand placement was detected in the answer.',
        };
    }

    private static function contextLabel(?string $label): string
    {
        return match ((string) $label) {
            'positive' => 'Positive',
            'negative' => 'Negative',
            'neutral' => 'Neutral',
            'not_present' => 'Not present',
            default => 'Unknown',
        };
    }

    private static function contextTone(?string $label): string
    {
        return match ((string) $label) {
            'positive' => 'emerald',
            'negative' => 'rose',
            'neutral' => 'amber',
            default => 'slate',
        };
    }

    private static function scoreTone(mixed $score): string
    {
        $score = self::floatOrNull($score);

        return match (true) {
            $score === null => 'slate',
            $score >= 0.75 => 'emerald',
            $score >= 0.4 => 'amber',
            default => 'rose',
        };
    }

    private static function rateTone(?float $rate): string
    {
        return self::scoreTone($rate);
    }

    private static function metricHelper(?float $score, string $fallback): string
    {
        if ($score === null) {
            return 'No scored run yet.';
        }

        return $fallback;
    }

    private static function trendLabel(mixed $current, mixed $comparison): string
    {
        $delta = self::deltaValue($current, $comparison);
        if ($delta === null) {
            return 'No baseline';
        }

        if ($delta > 0.005) {
            return 'Up';
        }

        if ($delta < -0.005) {
            return 'Down';
        }

        return 'Flat';
    }

    private static function deltaTone(mixed $current, mixed $comparison): string
    {
        $delta = self::deltaValue($current, $comparison);

        if ($delta === null) {
            return 'slate';
        }

        if ($delta > 0.005) {
            return 'emerald';
        }

        if ($delta < -0.005) {
            return 'rose';
        }

        return 'amber';
    }

    private static function deltaLabel(mixed $current, mixed $comparison, ?string $suffix): string
    {
        $delta = self::deltaValue($current, $comparison);

        if ($delta === null) {
            return $suffix ? 'No comparison ' . $suffix : 'No comparison';
        }

        $prefix = $delta > 0 ? '+' : '';
        $label = $prefix . number_format($delta * 100, 1);

        return trim($label . ($suffix ? ' ' . $suffix : ''));
    }

    private static function deltaValue(mixed $current, mixed $comparison): ?float
    {
        $current = self::floatOrNull($current);
        $comparison = self::floatOrNull($comparison);

        if ($current === null || $comparison === null) {
            return null;
        }

        return round($current - $comparison, 4);
    }

    private static function formatPercent(mixed $value, int $decimals = 1, bool $includePercentSuffix = false): string
    {
        $value = self::floatOrNull($value);
        if ($value === null) {
            return '-';
        }

        $formatted = number_format($value * 100, $decimals);

        return $includePercentSuffix ? $formatted . '%' : $formatted;
    }

    private static function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private static function limitText(string $value, int $limit = 120): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? '' : Str::limit($trimmed, $limit);
    }

    private static function firstActionSentence(array $recommendations): string
    {
        $first = (string) collect($recommendations['quick_wins'] ?? [])
            ->merge($recommendations['strategic'] ?? [])
            ->merge($recommendations['measurement'] ?? [])
            ->filter()
            ->first();

        return $first !== '' ? 'First action: ' . $first : 'No action recommendation is available yet.';
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function formatTimestamp(Carbon $timestamp): string
    {
        return $timestamp->format('M j, Y H:i');
    }
}
