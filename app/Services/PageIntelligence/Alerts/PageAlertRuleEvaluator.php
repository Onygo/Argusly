<?php

namespace App\Services\PageIntelligence\Alerts;

use App\Models\AlertRule;
use App\Models\MonitoredPage;
use App\Models\PageAlert;
use App\Models\PageCampaignMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageGeoObservation;
use App\Models\PageMention;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PageAlertRuleEvaluator
{
    public function __construct(private readonly PageAlertNotificationMapper $notifications)
    {
    }

    /**
     * @return Collection<int,PageAlert>
     */
    public function evaluate(?string $ruleId = null): Collection
    {
        $created = collect();

        AlertRule::query()
            ->active()
            ->when($ruleId !== null, fn (Builder $query) => $query->whereKey($ruleId))
            ->orderBy('created_at')
            ->chunkById(50, function (Collection $rules) use (&$created): void {
                foreach ($rules as $rule) {
                    $created = $created->merge($this->evaluateRule($rule));
                }
            });

        return $created->values();
    }

    /**
     * @return Collection<int,PageAlert>
     */
    public function evaluateRule(AlertRule $rule): Collection
    {
        $alerts = collect();

        foreach ($this->candidates($rule) as $candidate) {
            $alert = $this->fire($rule, $candidate);
            if ($alert) {
                $alerts->push($alert);
            }
        }

        $rule->forceFill(['last_evaluated_at' => now()])->save();

        return $alerts->values();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidates(AlertRule $rule): array
    {
        return match ($rule->trigger) {
            AlertRule::TRIGGER_NEW_BRAND_PAGE => $this->brandMentionCandidates($rule),
            AlertRule::TRIGGER_NEGATIVE_SENTIMENT => $this->negativeSentimentCandidates($rule),
            AlertRule::TRIGGER_COMPETITOR_CAMPAIGN_PAGE => $this->competitorCampaignCandidates($rule),
            AlertRule::TRIGGER_HIGH_PR_VALUE_PAGE => $this->highPrValueCandidates($rule),
            AlertRule::TRIGGER_SERP_TOP_10_GAIN => $this->serpMovementCandidates($rule, gain: true),
            AlertRule::TRIGGER_SERP_TOP_10_LOSS => $this->serpMovementCandidates($rule, gain: false),
            AlertRule::TRIGGER_SERP_COMPETITOR_TOP_10_GAIN => $this->serpCompetitorTop10Candidates($rule),
            AlertRule::TRIGGER_SERP_FEATURED_SNIPPET_GAIN => $this->serpFeaturedSnippetCandidates($rule, gain: true),
            AlertRule::TRIGGER_SERP_FEATURED_SNIPPET_LOSS => $this->serpFeaturedSnippetCandidates($rule, gain: false),
            AlertRule::TRIGGER_GEO_CITATION_GAIN => $this->geoMovementCandidates($rule, gain: true),
            AlertRule::TRIGGER_GEO_CITATION_LOSS => $this->geoMovementCandidates($rule, gain: false),
            AlertRule::TRIGGER_GEO_COMPETITOR_CITATION_GAIN => $this->geoCompetitorCitationGainCandidates($rule),
            AlertRule::TRIGGER_GEO_COMPETITOR_DISPLACED_CLIENT => $this->geoCompetitorDisplacementCandidates($rule),
            AlertRule::TRIGGER_CAMPAIGN_PICKUP => $this->campaignPickupCandidates($rule),
            AlertRule::TRIGGER_PR_VALUE_SPIKE => $this->prValueSpikeCandidates($rule),
            AlertRule::TRIGGER_HIGH_RISK_NEGATIVE_PAGE => $this->highRiskNegativePageCandidates($rule),
            AlertRule::TRIGGER_COMPETITOR_PRESSURE_SPIKE => $this->competitorPressureSpikeCandidates($rule),
            AlertRule::TRIGGER_HIGH_OPPORTUNITY_PAGE => $this->highOpportunityPageCandidates($rule),
            default => [],
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function brandMentionCandidates(AlertRule $rule): array
    {
        return PageMention::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('mention_type', 'brand')
            ->where('observed_at', '>=', $this->cutoff($rule))
            ->get()
            ->map(fn (PageMention $mention): array => $this->candidate(
                rule: $rule,
                key: 'brand_mention|'.$mention->id,
                page: $mention->page,
                title: 'New page mentions '.$mention->entity_name,
                summary: $mention->page?->title_current ?: $mention->evidence_snippet,
                evidence: ['page_mention_id' => $mention->id, 'snippet' => $mention->evidence_snippet],
                metrics: ['confidence' => (float) $mention->confidence_score],
            ))
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function negativeSentimentCandidates(AlertRule $rule): array
    {
        $maxScore = (float) data_get($rule->conditions_json, 'max_compound_score', -0.1);

        return PageSentiment::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('analyzed_at', '>=', $this->cutoff($rule))
            ->where(function (Builder $query) use ($maxScore): void {
                $query->where('label', 'negative')->orWhere('compound_score', '<=', $maxScore);
            })
            ->get()
            ->map(fn (PageSentiment $sentiment): array => $this->candidate(
                rule: $rule,
                key: 'negative_sentiment|'.$sentiment->id,
                page: $sentiment->page,
                title: 'Negative page sentiment detected',
                summary: $sentiment->explanation,
                evidence: ['page_sentiment_id' => $sentiment->id, 'target' => $sentiment->target_name],
                metrics: ['score' => (float) $sentiment->compound_score, 'confidence' => (float) $sentiment->confidence_score],
            ))
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function competitorCampaignCandidates(AlertRule $rule): array
    {
        $minScore = (float) data_get($rule->conditions_json, 'min_match_score', 0);

        return PageCompetitorMatch::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('observed_at', '>=', $this->cutoff($rule))
            ->where('match_score', '>=', $minScore)
            ->whereHas('page', function (Builder $query): void {
                $query->where('page_type', 'campaign_page')
                    ->orWhere('source_type', 'competitor_crawl');
            })
            ->get()
            ->map(fn (PageCompetitorMatch $match): array => $this->candidate(
                rule: $rule,
                key: 'competitor_campaign|'.$match->id,
                page: $match->page,
                title: 'Competitor campaign page discovered',
                summary: 'A competitor page matched campaign intelligence signals.',
                evidence: ['page_competitor_match_id' => $match->id, 'evidence' => $match->evidence_json],
                metrics: ['score' => (float) $match->match_score],
            ))
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function highPrValueCandidates(AlertRule $rule): array
    {
        $minScore = (float) data_get($rule->conditions_json, 'min_score', 80);

        return PagePrValue::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('calculated_at', '>=', $this->cutoff($rule))
            ->where('score', '>=', $minScore)
            ->get()
            ->map(fn (PagePrValue $value): array => $this->candidate(
                rule: $rule,
                key: 'high_pr_value|'.$value->id,
                page: $value->page,
                title: 'High PR value page discovered',
                summary: 'Estimated value: '.$value->currency.' '.$value->estimated_value_amount,
                evidence: ['page_pr_value_id' => $value->id, 'breakdown' => $value->breakdown_json],
                metrics: ['score' => (float) $value->score, 'estimated_value_amount' => (float) $value->estimated_value_amount],
            ))
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function serpMovementCandidates(AlertRule $rule, bool $gain): array
    {
        $candidates = [];
        $cutoff = $this->cutoff($rule);

        $currents = PageSerpObservation::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('observed_at', '>=', $cutoff)
                    ->orWhere('created_at', '>=', $cutoff);
            })
            ->whereNotNull('absolute_position')
            ->get();

        $history = $this->serpHistory($currents);

        $currents->each(function (PageSerpObservation $current) use (&$candidates, $rule, $gain, $history): void {
                $previous = $this->previousSerpObservationFromHistory($current, $history);
                if (! $previous || $previous->absolute_position === null) {
                    return;
                }

                if (! $this->serpMovementWithinWindow($rule, $current, $previous)) {
                    return;
                }

                $wasTop10 = $previous->absolute_position <= 10;
                $isTop10 = $current->absolute_position <= 10;
                if (($gain && (! $isTop10 || $wasTop10)) || (! $gain && (! $wasTop10 || $isTop10))) {
                    return;
                }

                $candidates[] = $this->candidate(
                    rule: $rule,
                    key: 'serp_top_10_'.($gain ? 'gain' : 'loss').'|'.$current->id,
                    page: $current->page,
                    title: $gain ? 'SERP top 10 gain' : 'SERP top 10 loss',
                    summary: $current->query.' moved from #'.$previous->absolute_position.' to #'.$current->absolute_position,
                    evidence: ['page_serp_observation_id' => $current->id, 'previous_page_serp_observation_id' => $previous->id],
                    metrics: [
                        'previous_position' => $previous->absolute_position,
                        'current_position' => $current->absolute_position,
                        'visibility_score' => (float) $current->visibility_score,
                    ],
                );
            });

        return $candidates;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function serpCompetitorTop10Candidates(AlertRule $rule): array
    {
        $candidates = [];

        $currents = PageSerpObservation::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('observed_at', '>=', $this->cutoff($rule))
            ->get();

        $history = $this->serpHistory($currents);

        $currents->each(function (PageSerpObservation $current) use (&$candidates, $rule, $history): void {
            $currentCompetitors = $this->top10Competitors($current);
            if ($currentCompetitors === []) {
                return;
            }

            $previous = $this->previousSerpObservationFromHistory($current, $history);
            $previousCompetitors = $previous ? $this->top10Competitors($previous) : [];

            foreach ($currentCompetitors as $key => $competitor) {
                if (array_key_exists($key, $previousCompetitors)) {
                    continue;
                }

                $name = (string) ($competitor['site_competitor_name'] ?? $competitor['domain'] ?? $key);
                $position = (int) ($competitor['position'] ?? $competitor['absolute_position'] ?? 0);

                $candidates[] = $this->candidate(
                    rule: $rule,
                    key: 'serp_competitor_top_10_gain|'.$current->id.'|'.$key,
                    page: $current->page,
                    title: 'Competitor entered SERP top 10',
                    summary: $name.' entered the top 10 for '.$current->query.($position > 0 ? ' at #'.$position : '.'),
                    evidence: [
                        'page_serp_observation_id' => $current->id,
                        'previous_page_serp_observation_id' => $previous?->id,
                        'competitor' => $competitor,
                    ],
                    metrics: [
                        'competitor_position' => $position ?: null,
                        'visibility_score' => (float) $current->visibility_score,
                    ],
                );
            }
        });

        return $candidates;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function serpFeaturedSnippetCandidates(AlertRule $rule, bool $gain): array
    {
        $candidates = [];

        $currents = PageSerpObservation::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('observed_at', '>=', $this->cutoff($rule))
            ->get();

        $history = $this->serpHistory($currents);

        $currents->each(function (PageSerpObservation $current) use (&$candidates, $rule, $gain, $history): void {
            $previous = $this->previousSerpObservationFromHistory($current, $history);
            if (! $previous) {
                return;
            }

            $hadSnippet = $this->hasFeaturedSnippet($previous);
            $hasSnippet = $this->hasFeaturedSnippet($current);

            if (($gain && (! $hasSnippet || $hadSnippet)) || (! $gain && (! $hadSnippet || $hasSnippet))) {
                return;
            }

            $candidates[] = $this->candidate(
                rule: $rule,
                key: 'serp_featured_snippet_'.($gain ? 'gain' : 'loss').'|'.$current->id,
                page: $current->page,
                title: $gain ? 'Featured snippet gained' : 'Featured snippet lost',
                summary: $current->query.' '.($gain ? 'gained' : 'lost').' a featured snippet.',
                evidence: [
                    'page_serp_observation_id' => $current->id,
                    'previous_page_serp_observation_id' => $previous->id,
                    'current_features' => $current->serp_features_json,
                    'previous_features' => $previous->serp_features_json,
                ],
                metrics: [
                    'current_position' => $current->absolute_position,
                    'previous_position' => $previous->absolute_position,
                    'visibility_score' => (float) $current->visibility_score,
                ],
            );
        });

        return $candidates;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function geoMovementCandidates(AlertRule $rule, bool $gain): array
    {
        $candidates = [];

        $currents = PageGeoObservation::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('observed_at', '>=', $this->cutoff($rule))
            ->whereNull('cited_url')
            ->get();

        $history = $this->geoHistory($currents);

        $currents->each(function (PageGeoObservation $current) use (&$candidates, $rule, $gain, $history): void {
                $previous = $this->previousGeoObservationFromHistory($current, $history);
                if (! $previous) {
                    return;
                }

                if (($gain && ($previous->client_cited || ! $current->client_cited)) || (! $gain && (! $previous->client_cited || $current->client_cited))) {
                    return;
                }

                $candidates[] = $this->candidate(
                    rule: $rule,
                    key: 'geo_citation_'.($gain ? 'gain' : 'loss').'|'.$current->id,
                    page: $current->page,
                    title: $gain ? 'GEO citation gain' : 'GEO citation loss',
                    summary: $current->query,
                    evidence: ['page_geo_observation_id' => $current->id, 'previous_page_geo_observation_id' => $previous->id],
                    metrics: [
                        'geo_visibility_score' => (float) $current->geo_visibility_score,
                        'topic_ownership_score' => (float) $current->topic_ownership_score,
                    ],
                );
            });

        return $candidates;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function geoCompetitorCitationGainCandidates(AlertRule $rule): array
    {
        $candidates = [];

        $currents = $this->currentRunLevelGeoObservations($rule);
        $history = $this->geoHistory($currents);

        $currents->each(function (PageGeoObservation $current) use (&$candidates, $rule, $history): void {
            $previous = $this->previousGeoObservationFromHistory($current, $history);
            if (! $previous || $previous->competitors_cited || ! $current->competitors_cited) {
                return;
            }

            $candidates[] = $this->candidate(
                rule: $rule,
                key: 'geo_competitor_citation_gain|'.$current->id,
                page: $this->representativeGeoPage($current),
                title: 'Competitor gained AI citation',
                summary: $current->query,
                evidence: ['page_geo_observation_id' => $current->id, 'previous_page_geo_observation_id' => $previous->id],
                metrics: [
                    'geo_visibility_score' => (float) $current->geo_visibility_score,
                    'topic_ownership_score' => (float) $current->topic_ownership_score,
                    'competitors_cited' => true,
                    'mentioned_competitors' => $current->mentioned_competitors_json,
                ],
            );
        });

        return $candidates;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function geoCompetitorDisplacementCandidates(AlertRule $rule): array
    {
        $candidates = [];

        $currents = $this->currentRunLevelGeoObservations($rule);
        $history = $this->geoHistory($currents);

        $currents->each(function (PageGeoObservation $current) use (&$candidates, $rule, $history): void {
            $previous = $this->previousGeoObservationFromHistory($current, $history);
            if (! $previous || ! $previous->client_cited || $current->client_cited || ! $current->competitors_cited) {
                return;
            }

            $candidates[] = $this->candidate(
                rule: $rule,
                key: 'geo_competitor_displaced_client|'.$current->id,
                page: $this->representativeGeoPage($current),
                title: 'Competitor displaced client AI citation',
                summary: $current->query,
                evidence: ['page_geo_observation_id' => $current->id, 'previous_page_geo_observation_id' => $previous->id],
                metrics: [
                    'previous_geo_visibility_score' => (float) $previous->geo_visibility_score,
                    'current_geo_visibility_score' => (float) $current->geo_visibility_score,
                    'previous_client_cited' => true,
                    'current_client_cited' => false,
                    'current_competitors_cited' => true,
                    'mentioned_competitors' => $current->mentioned_competitors_json,
                ],
            );
        });

        return $candidates;
    }

    /**
     * @return Collection<int,PageGeoObservation>
     */
    private function currentRunLevelGeoObservations(AlertRule $rule): Collection
    {
        return PageGeoObservation::query()
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('observed_at', '>=', $this->cutoff($rule))
            ->whereNull('cited_url')
            ->get();
    }

    private function representativeGeoPage(PageGeoObservation $observation): ?MonitoredPage
    {
        if ($observation->page) {
            return $observation->page;
        }

        return PageGeoObservation::query()
            ->where('llm_tracking_query_run_id', $observation->llm_tracking_query_run_id)
            ->whereNotNull('monitored_page_id')
            ->orderByDesc('client_cited')
            ->orderBy('citation_position')
            ->with('page.latestSnapshot')
            ->first()
            ?->page;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function campaignPickupCandidates(AlertRule $rule): array
    {
        $minScore = (float) data_get($rule->conditions_json, 'min_match_score', 0);

        return PageCampaignMatch::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('observed_at', '>=', $this->cutoff($rule))
            ->where('match_score', '>=', $minScore)
            ->get()
            ->map(fn (PageCampaignMatch $match): array => $this->candidate(
                rule: $rule,
                key: 'campaign_pickup|'.$match->id,
                page: $match->page,
                title: 'Campaign pickup detected',
                summary: 'A monitored page matched campaign evidence.',
                evidence: ['page_campaign_match_id' => $match->id, 'campaign_id' => $match->campaign_id, 'evidence' => $match->evidence_json],
                metrics: ['score' => (float) $match->match_score],
            ))
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function prValueSpikeCandidates(AlertRule $rule): array
    {
        $minDelta = (float) data_get($rule->conditions_json, 'min_delta', 20);
        $candidates = [];

        $currents = PagePrValue::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('calculated_at', '>=', $this->cutoff($rule))
            ->get();

        $history = $this->prValueHistory($currents);

        $currents->each(function (PagePrValue $current) use (&$candidates, $rule, $minDelta, $history): void {
                $previous = $history
                    ->where('monitored_page_id', $current->monitored_page_id)
                    ->where('model_key', $current->model_key)
                    ->filter(fn (PagePrValue $value): bool => $value->calculated_at < $current->calculated_at)
                    ->sortByDesc('calculated_at')
                    ->first();

                if (! $previous) {
                    return;
                }

                $delta = (float) $current->score - (float) $previous->score;
                if ($delta < $minDelta) {
                    return;
                }

                $candidates[] = $this->candidate(
                    rule: $rule,
                    key: 'pr_value_spike|'.$current->id,
                    page: $current->page,
                    title: 'PR value spike detected',
                    summary: 'PR value score rose by '.round($delta, 2).' points.',
                    evidence: ['page_pr_value_id' => $current->id, 'previous_page_pr_value_id' => $previous->id],
                    metrics: ['score' => (float) $current->score, 'previous_score' => (float) $previous->score, 'delta' => $delta],
                );
            });

        return $candidates;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function highRiskNegativePageCandidates(AlertRule $rule): array
    {
        $maxSentimentScore = (float) data_get($rule->conditions_json, 'max_compound_score', -0.1);
        $minAuthority = (float) data_get($rule->conditions_json, 'min_source_authority', 70);

        return PageSentiment::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('target_type', PageSentiment::TARGET_PAGE)
            ->where('analyzed_at', '>=', $this->cutoff($rule))
            ->where(function (Builder $query) use ($maxSentimentScore): void {
                $query->where('label', 'negative')->orWhere('compound_score', '<=', $maxSentimentScore);
            })
            ->get()
            ->filter(function (PageSentiment $sentiment) use ($minAuthority): bool {
                $source = $sentiment->page?->source;
                $authority = max((float) ($source?->authority_score ?? 0), (int) ($source?->trust_level ?? 0) * 10);

                return $authority >= $minAuthority;
            })
            ->map(function (PageSentiment $sentiment) use ($rule): array {
                $source = $sentiment->page?->source;
                $authority = max((float) ($source?->authority_score ?? 0), (int) ($source?->trust_level ?? 0) * 10);

                return $this->candidate(
                    rule: $rule,
                    key: 'high_risk_negative|'.$sentiment->id,
                    page: $sentiment->page,
                    title: 'High-risk negative page detected',
                    summary: $sentiment->explanation ?: 'Negative sentiment appeared on a high-authority source.',
                    evidence: ['page_sentiment_id' => $sentiment->id, 'source_authority' => $authority],
                    metrics: ['sentiment_score' => (float) $sentiment->compound_score, 'source_authority' => $authority],
                );
            })
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function competitorPressureSpikeCandidates(AlertRule $rule): array
    {
        $minPressure = (float) data_get($rule->conditions_json, 'min_competitor_pressure', 75);
        $minDelta = (float) data_get($rule->conditions_json, 'min_delta', 15);

        $scores = PageScore::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
            ->where('computed_at', '>=', $this->cutoff($rule))
            ->get();
        $history = $this->intelligenceScoreHistory($scores);

        return $scores
            ->filter(function (PageScore $score) use ($minPressure, $minDelta, $history): bool {
                $pressure = (float) data_get($score->breakdown_json, 'components.competitor_pressure.score', 0);
                if ($pressure < $minPressure) {
                    return false;
                }

                $previous = $history
                    ->where('monitored_page_id', $score->monitored_page_id)
                    ->filter(fn (PageScore $previous): bool => $previous->computed_at < $score->computed_at)
                    ->sortByDesc('computed_at')
                    ->first();
                if (! $previous) {
                    return true;
                }

                $previousPressure = (float) data_get($previous->breakdown_json, 'components.competitor_pressure.score', 0);

                return ($pressure - $previousPressure) >= $minDelta;
            })
            ->map(fn (PageScore $score): array => $this->candidate(
                rule: $rule,
                key: 'competitor_pressure_spike|'.$score->id,
                page: $score->page,
                title: 'Competitor pressure spike detected',
                summary: 'Competitor pressure reached '.number_format((float) data_get($score->breakdown_json, 'components.competitor_pressure.score', 0), 1).'.',
                evidence: ['page_score_id' => $score->id, 'breakdown' => $score->breakdown_json],
                metrics: [
                    'score' => (float) $score->score,
                    'competitor_pressure' => (float) data_get($score->breakdown_json, 'components.competitor_pressure.score', 0),
                ],
            ))
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function highOpportunityPageCandidates(AlertRule $rule): array
    {
        $minScore = (float) data_get($rule->conditions_json, 'min_score', 80);

        return PageScore::query()
            ->with('page.latestSnapshot')
            ->where('workspace_id', $rule->workspace_id)
            ->when($rule->client_site_id, fn (Builder $query) => $query->where('client_site_id', $rule->client_site_id))
            ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
            ->where('computed_at', '>=', $this->cutoff($rule))
            ->where('score', '>=', $minScore)
            ->get()
            ->map(fn (PageScore $score): array => $this->candidate(
                rule: $rule,
                key: 'high_opportunity|'.$score->id,
                page: $score->page,
                title: 'High-opportunity page discovered',
                summary: 'Argusly Intelligence Score reached '.number_format((float) $score->score, 1).'.',
                evidence: ['page_score_id' => $score->id, 'breakdown' => $score->breakdown_json],
                metrics: ['score' => (float) $score->score, 'confidence' => (float) data_get($score->metadata_json, 'confidence', 0)],
            ))
            ->all();
    }

    /**
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $metrics
     * @return array<string,mixed>
     */
    private function candidate(AlertRule $rule, string $key, ?MonitoredPage $page, string $title, ?string $summary, array $evidence, array $metrics): array
    {
        $signalEvent = $this->signalEvent($page, $evidence);
        $signalDetection = $signalEvent ? $this->signalDetection($signalEvent) : null;

        return [
            'dedupe_key' => $key,
            'monitored_page_id' => $page?->id,
            'page_snapshot_id' => $page?->latestSnapshot?->id,
            'signal_event_id' => $signalEvent?->id,
            'signal_detection_id' => $signalDetection?->id,
            'title' => $title,
            'summary' => $summary,
            'evidence_json' => $evidence,
            'metrics_json' => $metrics,
            'metadata_json' => ['rule_trigger' => $rule->trigger],
        ];
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function fire(AlertRule $rule, array $candidate): ?PageAlert
    {
        $alertKey = hash('sha256', implode('|', [$rule->id, $rule->trigger, $candidate['dedupe_key']]));
        $dedupeHash = $alertKey;

        return DB::transaction(function () use ($rule, $candidate, $alertKey, $dedupeHash): ?PageAlert {
            $alert = PageAlert::query()->firstOrCreate([
                'alert_rule_id' => $rule->id,
                'alert_key' => $alertKey,
            ], [
                'organization_id' => $rule->organization_id,
                'workspace_id' => $rule->workspace_id,
                'client_site_id' => $rule->client_site_id,
                'monitored_page_id' => $candidate['monitored_page_id'] ?? null,
                'page_snapshot_id' => $candidate['page_snapshot_id'] ?? null,
                'signal_event_id' => $candidate['signal_event_id'] ?? null,
                'signal_detection_id' => $candidate['signal_detection_id'] ?? null,
                'trigger' => $rule->trigger,
                'severity' => $rule->severity,
                'status' => PageAlert::STATUS_FIRED,
                'title' => (string) $candidate['title'],
                'summary' => $candidate['summary'] ?? null,
                'alert_key' => $alertKey,
                'dedupe_hash' => $dedupeHash,
                'evidence_json' => $candidate['evidence_json'] ?? [],
                'metrics_json' => $candidate['metrics_json'] ?? [],
                'metadata_json' => $candidate['metadata_json'] ?? [],
                'fired_at' => now(),
            ]);

            if (! $alert->wasRecentlyCreated) {
                return null;
            }

            $rule->forceFill(['last_fired_at' => now()])->save();

            return $this->notifications->notify($alert);
        });
    }

    private function cutoff(AlertRule $rule): Carbon
    {
        return now()->subMinutes(max(1, (int) data_get($rule->conditions_json, 'window_minutes', 1440)));
    }

    /**
     * @param Collection<int,PageSerpObservation> $currents
     * @return Collection<int,PageSerpObservation>
     */
    private function serpHistory(Collection $currents): Collection
    {
        if ($currents->isEmpty()) {
            return collect();
        }

        return PageSerpObservation::query()
            ->where('workspace_id', $currents->first()->workspace_id)
            ->whereIn('monitored_page_id', $currents->pluck('monitored_page_id')->filter()->unique()->values())
            ->whereIn('query_hash', $currents->pluck('query_hash')->unique()->values())
            ->whereIn('query', $currents->pluck('query')->filter()->unique()->values())
            ->whereIn('search_engine', $currents->pluck('search_engine')->unique()->values())
            ->whereIn('device', $currents->pluck('device')->unique()->values())
            ->where(function (Builder $query) use ($currents): void {
                $this->whereInOrNull($query, 'locale', $currents->pluck('locale'));
            })
            ->where(function (Builder $query) use ($currents): void {
                $this->whereInOrNull($query, 'country', $currents->pluck('country'));
            })
            ->where(function (Builder $query) use ($currents): void {
                $this->whereInOrNull($query, 'provider_key', $currents->pluck('provider_key'));
            })
            ->where(function (Builder $query) use ($currents): void {
                $this->whereInOrNull($query, 'serp_query_set_id', $currents->pluck('serp_query_set_id'));
            })
            ->where('observed_at', '<', $currents->max('observed_at'))
            ->whereNotNull('absolute_position')
            ->latest('observed_at')
            ->get();
    }

    /**
     * @param Collection<int,PageSerpObservation> $history
     */
    private function previousSerpObservationFromHistory(PageSerpObservation $current, Collection $history): ?PageSerpObservation
    {
        return $history
            ->where('monitored_page_id', $current->monitored_page_id)
            ->where('query_hash', $current->query_hash)
            ->where('search_engine', $current->search_engine)
            ->where('device', $current->device)
            ->filter(fn (PageSerpObservation $previous): bool => $previous->observed_at < $current->observed_at
                && $this->sameSerpHistoryScope($current, $previous))
            ->sortByDesc('observed_at')
            ->first();
    }

    private function serpMovementWithinWindow(AlertRule $rule, PageSerpObservation $current, PageSerpObservation $previous): bool
    {
        if ($current->observed_at === null || $previous->observed_at === null) {
            return true;
        }

        $windowMinutes = max(1, (int) data_get($rule->conditions_json, 'window_minutes', 1440));
        $movementMinutes = abs($current->observed_at->diffInMinutes($previous->observed_at));

        return $movementMinutes <= $windowMinutes;
    }

    /**
     * @param Collection<int,mixed> $values
     */
    private function whereInOrNull(Builder $query, string $column, Collection $values): void
    {
        $nonNullValues = $values->filter(fn (mixed $value): bool => $value !== null && $value !== '')->unique()->values();
        $hasNull = $values->contains(fn (mixed $value): bool => $value === null || $value === '');

        if ($nonNullValues->isNotEmpty()) {
            $query->whereIn($column, $nonNullValues);

            if ($hasNull) {
                $query->orWhereNull($column);
            }

            return;
        }

        $query->whereNull($column);
    }

    private function sameSerpHistoryScope(PageSerpObservation $current, PageSerpObservation $previous): bool
    {
        return $this->scopeValue($previous->query) === $this->scopeValue($current->query)
            && $this->scopeValue($previous->search_engine) === $this->scopeValue($current->search_engine)
            && $this->scopeValue($previous->device) === $this->scopeValue($current->device)
            && $this->scopeValue($previous->locale) === $this->scopeValue($current->locale)
            && $this->scopeValue($previous->country, uppercase: true) === $this->scopeValue($current->country, uppercase: true)
            && $this->scopeValue($previous->provider_key) === $this->scopeValue($current->provider_key)
            && $this->scopeValue($previous->serp_query_set_id) === $this->scopeValue($current->serp_query_set_id);
    }

    private function scopeValue(mixed $value, bool $uppercase = false): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $uppercase ? mb_strtoupper($value) : mb_strtolower($value);
    }

    private function hasFeaturedSnippet(PageSerpObservation $observation): bool
    {
        if ($observation->result_type === 'featured_snippet') {
            return true;
        }

        return collect((array) $observation->serp_features_json)
            ->contains(fn (mixed $feature): bool => strtolower((string) (is_array($feature) ? ($feature['type'] ?? $feature['key'] ?? $feature['name'] ?? '') : $feature)) === 'featured_snippet');
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function top10Competitors(PageSerpObservation $observation): array
    {
        return collect((array) $observation->competitor_presence_json)
            ->filter(function (mixed $competitor): bool {
                if (! is_array($competitor)) {
                    return false;
                }

                $position = (int) ($competitor['position'] ?? $competitor['absolute_position'] ?? 0);

                return $position > 0 && $position <= 10;
            })
            ->mapWithKeys(function (array $competitor): array {
                $key = strtolower((string) ($competitor['site_competitor_id'] ?? $competitor['domain'] ?? $competitor['name'] ?? ''));

                return $key === '' ? [] : [$key => $competitor];
            })
            ->all();
    }

    /**
     * @param Collection<int,PageGeoObservation> $currents
     * @return Collection<int,PageGeoObservation>
     */
    private function geoHistory(Collection $currents): Collection
    {
        if ($currents->isEmpty()) {
            return collect();
        }

        return PageGeoObservation::query()
            ->where('workspace_id', $currents->first()->workspace_id)
            ->whereIn('query_hash', $currents->pluck('query_hash')->unique()->values())
            ->whereIn('answer_engine', $currents->pluck('answer_engine')->unique()->values())
            ->whereNull('cited_url')
            ->where('observed_at', '<', $currents->max('observed_at'))
            ->latest('observed_at')
            ->get();
    }

    /**
     * @param Collection<int,PageGeoObservation> $history
     */
    private function previousGeoObservationFromHistory(PageGeoObservation $current, Collection $history): ?PageGeoObservation
    {
        return $history
            ->where('llm_tracking_query_id', $current->llm_tracking_query_id)
            ->where('query_hash', $current->query_hash)
            ->where('answer_engine', $current->answer_engine)
            ->where('provider', $current->provider)
            ->where('model', $current->model)
            ->filter(fn (PageGeoObservation $previous): bool => $previous->observed_at < $current->observed_at)
            ->sortByDesc('observed_at')
            ->first();
    }

    /**
     * @param Collection<int,PagePrValue> $currents
     * @return Collection<int,PagePrValue>
     */
    private function prValueHistory(Collection $currents): Collection
    {
        if ($currents->isEmpty()) {
            return collect();
        }

        return PagePrValue::query()
            ->where('workspace_id', $currents->first()->workspace_id)
            ->whereIn('monitored_page_id', $currents->pluck('monitored_page_id')->unique()->values())
            ->whereIn('model_key', $currents->pluck('model_key')->unique()->values())
            ->where('calculated_at', '<', $currents->max('calculated_at'))
            ->latest('calculated_at')
            ->get();
    }

    /**
     * @param Collection<int,PageScore> $currents
     * @return Collection<int,PageScore>
     */
    private function intelligenceScoreHistory(Collection $currents): Collection
    {
        if ($currents->isEmpty()) {
            return collect();
        }

        return PageScore::query()
            ->where('workspace_id', $currents->first()->workspace_id)
            ->whereIn('monitored_page_id', $currents->pluck('monitored_page_id')->unique()->values())
            ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
            ->where('computed_at', '<', $currents->max('computed_at'))
            ->latest('computed_at')
            ->get();
    }

    /**
     * @param array<string,mixed> $evidence
     */
    private function signalEvent(?MonitoredPage $page, array $evidence): ?SignalEvent
    {
        if (! $page) {
            return null;
        }

        return SignalEvent::query()
            ->where('workspace_id', $page->workspace_id)
            ->where(function (Builder $query) use ($page, $evidence): void {
                $query->where('metadata->monitored_page_id', $page->id);

                foreach (['page_serp_observation_id', 'page_geo_observation_id'] as $key) {
                    if (isset($evidence[$key])) {
                        $query->orWhere('metadata->'.$key, $evidence[$key]);
                    }
                }
            })
            ->latest('observed_at')
            ->first();
    }

    private function signalDetection(SignalEvent $event): ?SignalDetection
    {
        return SignalDetection::query()
            ->whereHas('events', fn (Builder $query) => $query->where('signal_events.id', $event->id))
            ->latest('last_seen_at')
            ->first();
    }
}
