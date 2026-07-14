<?php

namespace App\Services\BrandGrowthPlanning;

use App\Models\CompanyProfile;
use App\Models\Content;
use App\Models\LlmTrackingQuery;
use App\Models\MarketingObservation;
use App\Models\MonitoredPage;
use App\Models\Opportunity;
use App\Models\PageBrandMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageContentExtraction;
use App\Models\PageGeoObservation;
use App\Models\PageIntelligenceReport;
use App\Models\PageSerpObservation;
use App\Models\Persona;
use App\Models\SignalDetection;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\BrandIntelligence\BrandIntelligenceContextService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BrandGrowthPlanningContextCollector
{
    public function __construct(
        private readonly BrandIntelligenceContextService $brandIntelligence,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(Workspace $workspace): array
    {
        $workspace->loadMissing(['companyProfile', 'clientSites']);
        $brandSnapshot = $this->brandIntelligence->snapshotForWorkspace($workspace);
        $content = $this->contentContext($workspace);
        $pages = $this->pageContext($workspace);
        $pageIntelligence = $this->pageIntelligenceContext($workspace);
        $competitors = $this->competitorContext($workspace);
        $signals = $this->signalContext($workspace);
        $visibility = $this->visibilityContext($workspace);
        $observations = $this->observationContext($workspace);
        $personas = $this->personaContext($workspace);

        $missing = $this->missingContext($brandSnapshot, $personas, $content, $pages, $pageIntelligence, $competitors, $signals, $visibility, $observations);

        return [
            'schema_version' => 'brand_growth_planning.context.v1',
            'workspace' => [
                'id' => (string) $workspace->id,
                'organization_id' => $workspace->organization_id,
                'name' => $workspace->display_name,
            ],
            'company_profile' => $this->companyProfileContext($workspace->companyProfile),
            'brand_intelligence' => $brandSnapshot,
            'personas' => $personas,
            'content' => $content,
            'pages' => $pages,
            'page_intelligence' => $pageIntelligence,
            'competitors' => $competitors,
            'signals' => $signals,
            'visibility' => $visibility,
            'marketing_observations' => $observations,
            'available_sources' => [
                'brand_intelligence' => (bool) ($brandSnapshot['available'] ?? false),
                'company_profile' => $workspace->companyProfile instanceof CompanyProfile,
                'approved_personas' => (int) $personas['approved_count'] > 0,
                'content_inventory' => (int) $content['total'] > 0 || (int) $pages['total'] > 0,
                'page_intelligence' => (int) data_get($pageIntelligence, 'reports.total', 0) > 0
                    || (int) data_get($pageIntelligence, 'serp.total', 0) > 0
                    || (int) data_get($pageIntelligence, 'geo.total', 0) > 0
                    || (int) data_get($pageIntelligence, 'relationships.competitor_matches_total', 0) > 0
                    || (int) data_get($pageIntelligence, 'relationships.brand_matches_total', 0) > 0,
                'competitors' => (int) $competitors['active_count'] > 0,
                'signal_intelligence' => (int) $signals['total'] > 0,
                'ai_visibility' => (int) $visibility['llm_tracking_queries'] > 0,
                'marketing_observations' => (int) $observations['total'] > 0,
            ],
            'missing_information' => $missing,
            'source_reference_index' => $this->sourceReferenceIndex($brandSnapshot, $content, $pages, $pageIntelligence, $competitors, $signals, $visibility, $observations),
            'source_data_cutoff_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companyProfileContext(?CompanyProfile $profile): array
    {
        if (! $profile) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'id' => (string) $profile->id,
            'company_name' => $profile->company_name,
            'industry' => $profile->industry,
            'short_description' => $profile->short_description,
            'value_proposition' => $profile->value_proposition,
            'key_services' => $profile->keyServicesArray(),
            'proof_points' => $profile->proofPointsArray(),
            'target_audience' => $profile->target_audience,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personaContext(Workspace $workspace): array
    {
        $personas = Persona::query()
            ->where('organization_id', $workspace->organization_id)
            ->orderBy('type')
            ->orderBy('name')
            ->limit(25)
            ->get();

        return [
            'approved_count' => $personas->where('status', Persona::STATUS_APPROVED)->count(),
            'draft_count' => $personas->where('status', Persona::STATUS_DRAFT)->count(),
            'reviewed_count' => $personas->where('status', Persona::STATUS_REVIEWED)->count(),
            'rejected_count' => $personas->where('status', Persona::STATUS_REJECTED)->count(),
            'items' => $personas->map(fn (Persona $persona): array => [
                'id' => (string) $persona->id,
                'type' => $persona->type,
                'name' => $persona->name,
                'source_type' => $persona->source_type,
                'status' => $persona->status,
                'role' => data_get($persona->profile_data, 'role'),
                'industry_tags' => data_get($persona->profile_data, 'tags.industry', []),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contentContext(Workspace $workspace): array
    {
        $items = Content::query()
            ->where('workspace_id', $workspace->id)
            ->latest('updated_at')
            ->limit(100)
            ->get(['id', 'title', 'type', 'status', 'published_url', 'primary_keyword', 'buyer_persona_id', 'actual_word_count', 'created_at', 'updated_at']);

        $titles = $items->map(fn (Content $content): string => Str::lower((string) $content->title));

        return [
            'total' => Content::query()->where('workspace_id', $workspace->id)->count(),
            'sampled' => $items->count(),
            'with_persona' => $items->whereNotNull('buyer_persona_id')->count(),
            'case_study_count' => $this->countTitlesContaining($titles, ['case study', 'customer story', 'testimonial']),
            'benchmark_count' => $this->countTitlesContaining($titles, ['benchmark', 'report', 'research', 'data']),
            'roi_count' => $this->countTitlesContaining($titles, ['roi', 'business case', 'measurement', 'metrics']),
            'comparison_count' => $this->countTitlesContaining($titles, ['compare', 'comparison', 'alternative', 'versus', 'vs']),
            'items' => $items->take(20)->map(fn (Content $content): array => [
                'id' => (string) $content->id,
                'title' => $content->title,
                'type' => $content->type?->value ?? $content->type,
                'status' => $content->status,
                'primary_keyword' => $content->primary_keyword,
                'buyer_persona_id' => $content->buyer_persona_id,
                'url' => $content->published_url,
                'word_count' => $content->actual_word_count,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageContext(Workspace $workspace): array
    {
        $pages = MonitoredPage::query()
            ->where('workspace_id', $workspace->id)
            ->with('latestContentExtraction')
            ->latest('last_seen_at')
            ->limit(50)
            ->get();

        return [
            'total' => MonitoredPage::query()->where('workspace_id', $workspace->id)->count(),
            'extracted_total' => PageContentExtraction::query()->where('workspace_id', $workspace->id)->count(),
            'average_word_count' => (int) PageContentExtraction::query()->where('workspace_id', $workspace->id)->avg('word_count'),
            'items' => $pages->map(fn (MonitoredPage $page): array => [
                'id' => (string) $page->id,
                'title' => $page->title_current,
                'url' => $page->canonical_url,
                'domain' => $page->domain,
                'source_type' => $page->source_type,
                'page_type' => $page->page_type,
                'word_count' => $page->latestContentExtraction?->word_count,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageIntelligenceContext(Workspace $workspace): array
    {
        $reports = Schema::hasTable('page_intelligence_reports')
            ? PageIntelligenceReport::query()
                ->where('workspace_id', $workspace->id)
                ->latest('generated_at')
                ->limit(8)
                ->get(['id', 'title', 'report_type', 'status', 'summary', 'generated_at'])
            : collect();

        $serpObservations = Schema::hasTable('page_serp_observations')
            ? PageSerpObservation::query()
                ->where('workspace_id', $workspace->id)
                ->latest('observed_at')
                ->limit(25)
                ->get(['id', 'monitored_page_id', 'query', 'result_type', 'position', 'domain', 'title', 'keyword_intent', 'search_volume', 'click_potential', 'visibility_score', 'observed_at'])
            : collect();

        $geoObservations = Schema::hasTable('page_geo_observations')
            ? PageGeoObservation::query()
                ->where('workspace_id', $workspace->id)
                ->latest('observed_at')
                ->limit(25)
                ->get(['id', 'monitored_page_id', 'llm_tracking_query_id', 'query', 'answer_engine', 'provider', 'cited_domain', 'citation_count', 'client_cited', 'competitors_cited', 'brand_mentioned', 'sentiment', 'topic_ownership_score', 'consistency_score', 'geo_visibility_score', 'observed_at'])
            : collect();

        $competitorMatches = Schema::hasTable('page_competitor_matches')
            ? PageCompetitorMatch::query()
                ->where('workspace_id', $workspace->id)
                ->with('competitor:id,name,domain')
                ->orderByDesc('match_score')
                ->latest('observed_at')
                ->limit(25)
                ->get(['id', 'monitored_page_id', 'site_competitor_id', 'match_type', 'match_score', 'evidence_json', 'observed_at'])
            : collect();

        $brandMatches = Schema::hasTable('page_brand_matches')
            ? PageBrandMatch::query()
                ->where('workspace_id', $workspace->id)
                ->orderBy('match_score')
                ->latest('observed_at')
                ->limit(25)
                ->get(['id', 'monitored_page_id', 'brand_name', 'match_type', 'match_score', 'evidence_json', 'observed_at'])
            : collect();

        $serpTotal = Schema::hasTable('page_serp_observations')
            ? PageSerpObservation::query()->where('workspace_id', $workspace->id)->count()
            : 0;
        $geoTotal = Schema::hasTable('page_geo_observations')
            ? PageGeoObservation::query()->where('workspace_id', $workspace->id)->count()
            : 0;
        $competitorMatchTotal = Schema::hasTable('page_competitor_matches')
            ? PageCompetitorMatch::query()->where('workspace_id', $workspace->id)->count()
            : 0;
        $brandMatchTotal = Schema::hasTable('page_brand_matches')
            ? PageBrandMatch::query()->where('workspace_id', $workspace->id)->count()
            : 0;

        return [
            'reports' => [
                'total' => Schema::hasTable('page_intelligence_reports')
                    ? PageIntelligenceReport::query()->where('workspace_id', $workspace->id)->count()
                    : 0,
                'generated_count' => Schema::hasTable('page_intelligence_reports')
                    ? PageIntelligenceReport::query()->where('workspace_id', $workspace->id)->where('status', PageIntelligenceReport::STATUS_GENERATED)->count()
                    : 0,
                'items' => $reports->map(fn (PageIntelligenceReport $report): array => [
                    'id' => (string) $report->id,
                    'title' => $report->title,
                    'report_type' => $report->report_type,
                    'status' => $report->status,
                    'summary' => $report->summary,
                    'generated_at' => $report->generated_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'serp' => [
                'total' => $serpTotal,
                'sampled' => $serpObservations->count(),
                'average_visibility_score' => $serpObservations->avg(fn (PageSerpObservation $observation): float => (float) $observation->visibility_score),
                'low_visibility_count' => $serpObservations->filter(fn (PageSerpObservation $observation): bool => (float) $observation->visibility_score < 40)->count(),
                'high_intent_count' => $serpObservations->filter(fn (PageSerpObservation $observation): bool => in_array((string) $observation->keyword_intent, ['commercial', 'transactional', 'comparison'], true))->count(),
                'items' => $serpObservations->map(fn (PageSerpObservation $observation): array => [
                    'id' => (string) $observation->id,
                    'monitored_page_id' => $observation->monitored_page_id ? (string) $observation->monitored_page_id : null,
                    'query' => $observation->query,
                    'result_type' => $observation->result_type,
                    'position' => $observation->position,
                    'domain' => $observation->domain,
                    'title' => $observation->title,
                    'keyword_intent' => $observation->keyword_intent,
                    'search_volume' => $observation->search_volume,
                    'click_potential' => $observation->click_potential !== null ? (float) $observation->click_potential : null,
                    'visibility_score' => (float) $observation->visibility_score,
                    'observed_at' => $observation->observed_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'geo' => [
                'total' => $geoTotal,
                'sampled' => $geoObservations->count(),
                'average_visibility_score' => $geoObservations->avg(fn (PageGeoObservation $observation): float => (float) $observation->geo_visibility_score),
                'client_cited_count' => $geoObservations->where('client_cited', true)->count(),
                'competitors_cited_count' => $geoObservations->where('competitors_cited', true)->count(),
                'brand_mentioned_count' => $geoObservations->where('brand_mentioned', true)->count(),
                'negative_or_neutral_count' => $geoObservations->filter(fn (PageGeoObservation $observation): bool => in_array((string) $observation->sentiment, ['negative', 'neutral'], true))->count(),
                'items' => $geoObservations->map(fn (PageGeoObservation $observation): array => [
                    'id' => (string) $observation->id,
                    'monitored_page_id' => $observation->monitored_page_id ? (string) $observation->monitored_page_id : null,
                    'llm_tracking_query_id' => $observation->llm_tracking_query_id ? (string) $observation->llm_tracking_query_id : null,
                    'query' => $observation->query,
                    'answer_engine' => $observation->answer_engine,
                    'provider' => $observation->provider,
                    'cited_domain' => $observation->cited_domain,
                    'citation_count' => (int) $observation->citation_count,
                    'client_cited' => (bool) $observation->client_cited,
                    'competitors_cited' => (bool) $observation->competitors_cited,
                    'brand_mentioned' => (bool) $observation->brand_mentioned,
                    'sentiment' => $observation->sentiment,
                    'topic_ownership_score' => $observation->topic_ownership_score !== null ? (float) $observation->topic_ownership_score : null,
                    'consistency_score' => $observation->consistency_score !== null ? (float) $observation->consistency_score : null,
                    'geo_visibility_score' => (float) $observation->geo_visibility_score,
                    'observed_at' => $observation->observed_at?->toIso8601String(),
                ])->values()->all(),
            ],
            'relationships' => [
                'competitor_matches_total' => $competitorMatchTotal,
                'brand_matches_total' => $brandMatchTotal,
                'high_competitor_match_count' => $competitorMatches->filter(fn (PageCompetitorMatch $match): bool => (float) $match->match_score >= 0.75)->count(),
                'weak_brand_match_count' => $brandMatches->filter(fn (PageBrandMatch $match): bool => (float) $match->match_score < 0.45)->count(),
                'competitor_matches' => $competitorMatches->map(fn (PageCompetitorMatch $match): array => [
                    'id' => (string) $match->id,
                    'monitored_page_id' => (string) $match->monitored_page_id,
                    'site_competitor_id' => (string) $match->site_competitor_id,
                    'competitor_name' => $match->competitor?->name,
                    'competitor_domain' => $match->competitor?->domain,
                    'match_type' => $match->match_type,
                    'match_score' => (float) $match->match_score,
                    'evidence' => $match->evidence_json,
                    'observed_at' => $match->observed_at?->toIso8601String(),
                ])->values()->all(),
                'brand_matches' => $brandMatches->map(fn (PageBrandMatch $match): array => [
                    'id' => (string) $match->id,
                    'monitored_page_id' => (string) $match->monitored_page_id,
                    'brand_name' => $match->brand_name,
                    'match_type' => $match->match_type,
                    'match_score' => (float) $match->match_score,
                    'evidence' => $match->evidence_json,
                    'observed_at' => $match->observed_at?->toIso8601String(),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function competitorContext(Workspace $workspace): array
    {
        $competitors = SiteCompetitor::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['contentItems', 'topicSignals', 'contentOpportunities'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(25)
            ->get();

        return [
            'active_count' => $competitors->where('is_active', true)->count(),
            'total' => $competitors->count(),
            'items' => $competitors->map(fn (SiteCompetitor $competitor): array => [
                'id' => (string) $competitor->id,
                'name' => $competitor->name,
                'domain' => $competitor->domain,
                'is_active' => (bool) $competitor->is_active,
                'content_items_count' => (int) $competitor->content_items_count,
                'topic_signals_count' => (int) $competitor->topic_signals_count,
                'content_opportunities_count' => (int) $competitor->content_opportunities_count,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signalContext(Workspace $workspace): array
    {
        $detections = SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->latest('last_seen_at')
            ->limit(25)
            ->get();

        return [
            'total' => SignalDetection::query()->where('workspace_id', $workspace->id)->count(),
            'high_priority_count' => SignalDetection::query()->where('workspace_id', $workspace->id)->where('priority_score', '>=', 75)->count(),
            'open_opportunities' => Opportunity::query()->where('workspace_id', $workspace->id)->whereIn('status', ['open', 'reviewing', 'approved', 'planned'])->count(),
            'items' => $detections->map(fn (SignalDetection $detection): array => [
                'id' => (string) $detection->id,
                'category' => $detection->category,
                'type' => $detection->type,
                'title' => $detection->title,
                'topic' => $detection->primary_topic,
                'entity' => $detection->primary_entity,
                'priority_score' => (float) $detection->priority_score,
                'confidence_score' => (float) $detection->confidence_score,
                'opportunity_score' => (float) $detection->opportunity_score,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visibilityContext(Workspace $workspace): array
    {
        $queries = LlmTrackingQuery::query()
            ->where('workspace_id', $workspace->id)
            ->latest('updated_at')
            ->limit(20)
            ->get(['id', 'query_text', 'target_brand', 'tags', 'updated_at']);

        return [
            'llm_tracking_queries' => LlmTrackingQuery::query()->where('workspace_id', $workspace->id)->count(),
            'items' => $queries->map(fn (LlmTrackingQuery $query): array => [
                'id' => (string) $query->id,
                'query' => $query->query_text,
                'target_brand' => $query->target_brand,
                'topic' => collect($query->tags ?? [])->first(),
                'intent' => null,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function observationContext(Workspace $workspace): array
    {
        if (! Schema::hasTable('marketing_observations')) {
            return ['total' => 0, 'items' => []];
        }

        $items = MarketingObservation::query()
            ->where('workspace_id', $workspace->id)
            ->latest('period_end')
            ->limit(20)
            ->get(['id', 'metric_key', 'metric_value', 'unit', 'period_start', 'period_end', 'confidence_score']);

        return [
            'total' => MarketingObservation::query()->where('workspace_id', $workspace->id)->count(),
            'items' => $items->map(fn (MarketingObservation $observation): array => [
                'id' => (string) $observation->id,
                'metric_key' => $observation->metric_key,
                'value' => (float) $observation->metric_value,
                'unit' => $observation->unit,
                'period_start' => $observation->period_start?->toDateString(),
                'period_end' => $observation->period_end?->toDateString(),
                'confidence_score' => $observation->confidence_score !== null ? (float) $observation->confidence_score : null,
            ])->values()->all(),
        ];
    }

    /**
     * @param  iterable<int, string>  $titles
     * @param  array<int, string>  $needles
     */
    private function countTitlesContaining(iterable $titles, array $needles): int
    {
        return collect($titles)
            ->filter(fn (string $title): bool => collect($needles)->contains(fn (string $needle): bool => str_contains($title, $needle)))
            ->count();
    }

    /**
     * @return array<int, string>
     */
    private function missingContext(
        array $brandSnapshot,
        array $personas,
        array $content,
        array $pages,
        array $pageIntelligence,
        array $competitors,
        array $signals,
        array $visibility,
        array $observations,
    ): array
    {
        $missing = [];

        if (! (bool) ($brandSnapshot['available'] ?? false)) {
            $missing[] = 'No approved Brand Intelligence snapshot is available.';
        }

        foreach ([$personas, $content, $pages, $pageIntelligence, $competitors, $signals, $visibility, $observations] as $context) {
            $missing = array_merge($missing, Arr::wrap($context['missing_information'] ?? []));
        }

        if ((int) ($personas['approved_count'] ?? 0) === 0) {
            $missing[] = 'No approved personas are available.';
        }

        if ((int) ($content['total'] ?? 0) === 0 && (int) ($pages['total'] ?? 0) === 0) {
            $missing[] = 'No owned content or observed website pages are available.';
        }

        if ((int) ($pages['total'] ?? 0) > 0
            && (int) data_get($pageIntelligence, 'serp.total', 0) === 0
            && (int) data_get($pageIntelligence, 'geo.total', 0) === 0
            && (int) data_get($pageIntelligence, 'relationships.competitor_matches_total', 0) === 0
            && (int) data_get($pageIntelligence, 'relationships.brand_matches_total', 0) === 0) {
            $missing[] = 'Page Intelligence observations are not yet available for observed pages.';
        }

        if ((int) ($competitors['active_count'] ?? 0) === 0) {
            $missing[] = 'No active competitors are configured.';
        }

        if ((int) ($signals['total'] ?? 0) === 0) {
            $missing[] = 'No Signal Intelligence detections are available.';
        }

        if ((int) ($visibility['llm_tracking_queries'] ?? 0) === 0) {
            $missing[] = 'No AI visibility tracking queries are configured.';
        }

        if ((int) ($observations['total'] ?? 0) === 0) {
            $missing[] = 'No connector-backed marketing observations are available.';
        }

        return collect($missing)->filter()->unique()->values()->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function sourceReferenceIndex(array $brandSnapshot, array $content, array $pages, array $pageIntelligence, array $competitors, array $signals, array $visibility, array $observations): array
    {
        return [
            'company_intelligence_profile_ids' => collect([data_get($brandSnapshot, 'sources.company_intelligence_profile_id')])->filter()->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'brand_voice_ids' => collect([data_get($brandSnapshot, 'sources.brand_voice_id')])->filter()->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'brand_context_ids' => collect([data_get($brandSnapshot, 'sources.brand_context_id')])->filter()->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'content_ids' => collect($content['items'] ?? [])->pluck('id')->filter()->values()->all(),
            'monitored_page_ids' => collect($pages['items'] ?? [])->pluck('id')->filter()->values()->all(),
            'page_intelligence_report_ids' => collect(data_get($pageIntelligence, 'reports.items', []))->pluck('id')->filter()->values()->all(),
            'page_serp_observation_ids' => collect(data_get($pageIntelligence, 'serp.items', []))->pluck('id')->filter()->values()->all(),
            'page_geo_observation_ids' => collect(data_get($pageIntelligence, 'geo.items', []))->pluck('id')->filter()->values()->all(),
            'page_competitor_match_ids' => collect(data_get($pageIntelligence, 'relationships.competitor_matches', []))->pluck('id')->filter()->values()->all(),
            'page_brand_match_ids' => collect(data_get($pageIntelligence, 'relationships.brand_matches', []))->pluck('id')->filter()->values()->all(),
            'site_competitor_ids' => collect($competitors['items'] ?? [])->pluck('id')->filter()->values()->all(),
            'signal_detection_ids' => collect($signals['items'] ?? [])->pluck('id')->filter()->values()->all(),
            'llm_tracking_query_ids' => collect($visibility['items'] ?? [])->pluck('id')->filter()->values()->all(),
            'marketing_observation_ids' => collect($observations['items'] ?? [])->pluck('id')->filter()->values()->all(),
        ];
    }
}
