<?php

namespace App\Services\PageIntelligence\Reports;

use App\Contracts\PageIntelligence\ScheduledBriefingContract;
use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\MarketPack;
use App\Models\MarketPackInstallation;
use App\Models\MonitoredPage;
use App\Models\PageAlert;
use App\Models\PageCampaignMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageContentExtraction;
use App\Models\PageGeoObservation;
use App\Models\PageIntelligenceReport;
use App\Models\PageIntelligenceReportSnapshotAllocation;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\PageSnapshot;
use App\Models\SignalEvent;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportBuilder implements ScheduledBriefingContract
{
    public const TYPE_WEEKLY = 'weekly_intelligence_briefing';
    public const TYPE_MONTHLY = 'monthly_market_report';
    public const TYPE_COMPETITOR = 'competitor_movement_report';
    public const TYPE_CAMPAIGN = 'campaign_impact_report';
    public const TYPE_VISIBILITY = 'serp_geo_visibility_report';

    public const TEMPLATE_VERSION = 'page-intelligence-report-v1';

    private const MOVEMENT_QUERY_LIMIT = 500;

    private const REPORT_TYPES = [
        self::TYPE_WEEKLY => [
            'label' => 'Weekly Intelligence Briefing',
            'days' => 7,
            'cadence' => 'weekly',
            'focus' => 'Executive-ready movement, opportunities and risks from the last week.',
        ],
        self::TYPE_MONTHLY => [
            'label' => 'Monthly Market Report',
            'days' => 30,
            'cadence' => 'monthly',
            'focus' => 'Market-pack-aware summary of page value, market movement and visibility.',
        ],
        self::TYPE_COMPETITOR => [
            'label' => 'Competitor Movement Report',
            'days' => 30,
            'cadence' => 'recurring',
            'focus' => 'Competitor mentions, overlaps, pressure and evidence pages.',
        ],
        self::TYPE_CAMPAIGN => [
            'label' => 'Campaign Impact Report',
            'days' => 30,
            'cadence' => 'recurring',
            'focus' => 'Campaign-linked earned pages, value and visibility changes.',
        ],
        self::TYPE_VISIBILITY => [
            'label' => 'SERP/GEO Visibility Report',
            'days' => 30,
            'cadence' => 'recurring',
            'focus' => 'Search and answer-engine visibility movements with cited evidence.',
        ],
    ];

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function reportTypes(): array
    {
        return self::REPORT_TYPES;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function prepare(Workspace $workspace, string $reportType, array $options = [], ?User $user = null): PageIntelligenceReport
    {
        return $this->generate($workspace, $reportType, $options, $user);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function generate(Workspace $workspace, string $reportType, array $options = [], ?User $user = null): PageIntelligenceReport
    {
        if (! isset(self::REPORT_TYPES[$reportType])) {
            throw new InvalidArgumentException('Unsupported Page Intelligence report type: '.$reportType);
        }

        $this->assertTenantScope($workspace, $options, $user);

        $template = self::REPORT_TYPES[$reportType];
        [$periodStart, $periodEnd] = $this->period($template, $options);
        $marketPackKey = trim((string) ($options['market_pack_key'] ?? $options['market_pack'] ?? '')) ?: null;
        $clientSiteId = trim((string) ($options['client_site_id'] ?? '')) ?: null;
        $marketPack = $this->resolveInstalledMarketPack($workspace, $marketPackKey, $clientSiteId);
        $identity = $this->reportIdentity($workspace, $reportType, $periodStart, $periodEnd, $marketPackKey, $clientSiteId);
        $idempotencyKey = $this->idempotencyKey($identity, $options);

        if ($idempotencyKey !== null) {
            $existing = PageIntelligenceReport::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof PageIntelligenceReport) {
                return $existing;
            }
        }

        $context = [
            'workspace' => $workspace,
            'type' => $reportType,
            'template' => $template,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'market_pack_key' => $marketPackKey,
            'market_pack' => $marketPack,
            'client_site_id' => $clientSiteId,
            'campaign_id' => trim((string) ($options['campaign_id'] ?? '')) ?: null,
            'query_parameters' => $this->queryParameters($options),
        ];

        $sections = [
            'executive_summary' => [],
            'top_opportunities' => $this->topOpportunities($context),
            'top_risks' => $this->topRisks($context),
            'competitor_movements' => $this->competitorMovements($context),
            'serp_movements' => $this->serpMovements($context),
            'geo_ai_visibility_movements' => $this->geoMovements($context),
            'highest_pr_value_pages' => $this->highestPrValuePages($context),
            'market_pack_summary' => $this->marketPackSummary($context),
            'campaign_impact' => $this->campaignImpact($context),
        ];

        $sections['recommended_actions'] = $this->recommendedActions($sections);
        $sections['executive_summary'] = $this->executiveSummary($workspace, $context, $sections);

        $evidenceLinks = $this->evidenceLinks($sections);
        $provenance = $this->provenance($workspace, $context, $sections);
        $provenance['generated_by'] = $user?->id;
        $provenance['data_fingerprint'] = $this->dataFingerprint($provenance);
        $title = $this->title($template['label'], $periodStart, $periodEnd, $marketPack);
        $summary = (string) data_get($sections, 'executive_summary.narrative');

        $payload = [
            'type' => $reportType,
            'label' => $template['label'],
            'template_version' => self::TEMPLATE_VERSION,
            'title' => $title,
            'period' => [
                'start' => $periodStart->toIso8601String(),
                'end' => $periodEnd->toIso8601String(),
                'cadence' => $template['cadence'],
            ],
            'market_pack' => $marketPack ? [
                'id' => $marketPack->id,
                'key' => $marketPack->key,
                'name' => $marketPack->name,
                'category' => $marketPack->market_category,
                'version' => $marketPack->version,
            ] : null,
            'sections' => $sections,
            'evidence_links' => $evidenceLinks,
            'provenance' => $provenance,
            'export' => [
                'ready' => true,
                'layout' => 'layouts.export-pdf',
                'route' => 'app.page-intelligence.reports.export',
                'artifact_type' => PageIntelligenceReport::ARTIFACT_TYPE_PDF,
                'sections' => array_keys($sections),
                'download_label' => $template['label'].' export',
            ],
        ];

        return DB::transaction(function () use (
            $workspace,
            $reportType,
            $periodStart,
            $periodEnd,
            $marketPackKey,
            $clientSiteId,
            $marketPack,
            $identity,
            $idempotencyKey,
            $title,
            $summary,
            $payload,
            $provenance,
            $user
        ): PageIntelligenceReport {
            if ($idempotencyKey !== null) {
                $existing = PageIntelligenceReport::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof PageIntelligenceReport) {
                    return $existing;
                }
            }

            $snapshotVersion = $this->allocateSnapshotVersion(
                $workspace,
                $reportType,
                $periodStart,
                $periodEnd,
                $marketPackKey,
                $clientSiteId,
                $identity['hash'],
            );

            $payload['snapshot_version'] = $snapshotVersion;
            $artifactSourceChecksum = hash('sha256', json_encode([
                'identity_hash' => $identity['hash'],
                'snapshot_version' => $snapshotVersion,
                'payload' => $payload,
                'provenance_fingerprint' => $provenance['data_fingerprint'],
            ], JSON_THROW_ON_ERROR));

            return PageIntelligenceReport::query()->create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => $workspace->id,
                'client_site_id' => $clientSiteId,
                'market_pack_id' => $marketPack?->id,
                'market_pack_key' => $marketPackKey,
                'report_type' => $reportType,
                'identity_hash' => $identity['hash'],
                'idempotency_key' => $idempotencyKey,
                'title' => $title,
                'status' => PageIntelligenceReport::STATUS_GENERATED,
                'snapshot_version' => $snapshotVersion,
                'template_version' => self::TEMPLATE_VERSION,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'summary' => $summary,
                'payload_json' => $payload,
                'provenance_json' => $provenance,
                'generated_by' => $user?->id,
                'generated_at' => now(),
                'artifact_type' => PageIntelligenceReport::ARTIFACT_TYPE_PDF,
                'artifact_storage_path' => null,
                'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_PENDING,
                'artifact_generated_at' => null,
                'artifact_checksum' => null,
                'artifact_source_checksum' => $artifactSourceChecksum,
                'artifact_failed_at' => null,
                'artifact_error' => null,
                'artifact_attempt_count' => 0,
            ]);
        });
    }

    /**
     * @param array<string,mixed> $template
     * @param array<string,mixed> $options
     * @return array{0:Carbon,1:Carbon}
     */
    private function period(array $template, array $options): array
    {
        $periodEnd = isset($options['period_end'])
            ? Carbon::parse((string) $options['period_end'])->endOfDay()
            : now();
        $periodStart = isset($options['period_start'])
            ? Carbon::parse((string) $options['period_start'])->startOfDay()
            : $periodEnd->copy()->subDays((int) $template['days'])->startOfDay();

        return [$periodStart, $periodEnd];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function topOpportunities(array $context): array
    {
        $scores = PageScore::query()
            ->where('workspace_id', $context['workspace']->id)
            ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
            ->whereBetween('computed_at', [$context['period_start'], $context['period_end']])
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with('page:id,title_current,canonical_url,domain')
            ->orderByDesc('score')
            ->limit(8)
            ->get();

        return $scores
            ->map(fn (PageScore $score): array => [
                'title' => $this->pageTitle($score->page),
                'summary' => 'High Intelligence Score page with strong briefing potential.',
                'score' => round((float) $score->score, 2),
                'confidence' => round((float) data_get($score->metadata_json, 'confidence', 0), 2),
                'recommended_action' => 'Amplify this page and use its evidence in customer-facing messaging.',
                'source_model' => PageScore::class,
                'source_id' => $score->id,
                'page_id' => $score->monitored_page_id,
                'evidence' => $this->pageEvidence($score->page, PageScore::class, $score->id),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function topRisks(array $context): array
    {
        $alerts = PageAlert::query()
            ->where('workspace_id', $context['workspace']->id)
            ->whereBetween('fired_at', [$context['period_start'], $context['period_end']])
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with(['page:id,title_current,canonical_url,domain', 'recommendedAction:id,title'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END DESC")
            ->latest('fired_at')
            ->limit(6)
            ->get();

        $sentiments = PageSentiment::query()
            ->where('workspace_id', $context['workspace']->id)
            ->where('label', 'negative')
            ->whereBetween('analyzed_at', [$context['period_start'], $context['period_end']])
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with('page:id,title_current,canonical_url,domain')
            ->orderBy('compound_score')
            ->limit(6)
            ->get();

        return $alerts->map(fn (PageAlert $alert): array => [
            'title' => $alert->title,
            'summary' => $alert->summary ?: 'Page alert requires review.',
            'severity' => $alert->severity,
            'status' => $alert->status,
            'recommended_action' => $alert->recommendedAction?->title ?: 'Review the page evidence and decide whether to respond, refresh or brief the customer.',
            'source_model' => PageAlert::class,
            'source_id' => $alert->id,
            'page_id' => $alert->monitored_page_id,
            'evidence' => $this->pageEvidence($alert->page, PageAlert::class, $alert->id),
        ])->merge($sentiments->map(fn (PageSentiment $sentiment): array => [
            'title' => 'Negative sentiment: '.$this->pageTitle($sentiment->page),
            'summary' => $sentiment->explanation ?: 'Negative page sentiment detected.',
            'severity' => 'medium',
            'score' => round((float) $sentiment->compound_score, 4),
            'recommended_action' => 'Inspect the negative framing and prepare customer talking points.',
            'source_model' => PageSentiment::class,
            'source_id' => $sentiment->id,
            'page_id' => $sentiment->monitored_page_id,
            'evidence' => $this->pageEvidence($sentiment->page, PageSentiment::class, $sentiment->id),
        ]))->take(8)->values()->all();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function competitorMovements(array $context): array
    {
        $matches = PageCompetitorMatch::query()
            ->where('workspace_id', $context['workspace']->id)
            ->whereBetween('observed_at', [$context['period_start'], $context['period_end']])
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with(['page:id,title_current,canonical_url,domain', 'competitor:id,name,domain'])
            ->orderByDesc('match_score')
            ->limit(8)
            ->get();

        $serpOverlaps = PageSerpObservation::query()
            ->where('workspace_id', $context['workspace']->id)
            ->whereBetween('observed_at', [$context['period_start'], $context['period_end']])
            ->whereNotNull('competitor_presence_json')
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with('page:id,title_current,canonical_url,domain')
            ->latest('observed_at')
            ->limit(8)
            ->get()
            ->filter(fn (PageSerpObservation $observation): bool => count((array) $observation->competitor_presence_json) > 0);

        return $matches->map(fn (PageCompetitorMatch $match): array => [
            'title' => ($match->competitor?->name ?: 'Competitor').' appeared on '.$this->pageTitle($match->page),
            'summary' => str($match->match_type)->headline().' match with '.number_format((float) $match->match_score, 1).' confidence.',
            'competitor' => $match->competitor?->name,
            'domain' => $match->competitor?->domain,
            'score' => round((float) $match->match_score, 2),
            'source_model' => PageCompetitorMatch::class,
            'source_id' => $match->id,
            'page_id' => $match->monitored_page_id,
            'evidence' => $this->pageEvidence($match->page, PageCompetitorMatch::class, $match->id),
        ])->merge($serpOverlaps->map(fn (PageSerpObservation $observation): array => [
            'title' => 'SERP overlap on '.$observation->query,
            'summary' => count((array) $observation->competitor_presence_json).' competitor result(s) visible near this query.',
            'competitors' => collect((array) $observation->competitor_presence_json)->pluck('domain')->filter()->values()->all(),
            'source_model' => PageSerpObservation::class,
            'source_id' => $observation->id,
            'page_id' => $observation->monitored_page_id,
            'evidence' => $this->pageEvidence($observation->page, PageSerpObservation::class, $observation->id),
        ]))->take(10)->values()->all();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function serpMovements(array $context): array
    {
        $observations = PageSerpObservation::query()
            ->where('workspace_id', $context['workspace']->id)
            ->whereBetween('observed_at', [$context['period_start'], $context['period_end']])
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with('page:id,title_current,canonical_url,domain')
            ->orderByDesc('observed_at')
            ->limit(self::MOVEMENT_QUERY_LIMIT)
            ->get()
            ->sortBy('observed_at');

        return $observations
            ->groupBy(fn (PageSerpObservation $observation): string => implode('|', [
                $observation->query_hash ?: md5((string) $observation->query),
                $observation->monitored_page_id,
                $observation->country,
                $observation->device,
            ]))
            ->map(function (Collection $group): ?array {
                /** @var PageSerpObservation|null $latest */
                $latest = $group->last();
                /** @var PageSerpObservation|null $previous */
                $previous = $group->count() > 1 ? $group->slice(-2, 1)->first() : null;
                if (! $latest) {
                    return null;
                }

                $latestPosition = $latest->absolute_position ?: $latest->position;
                $previousPosition = $previous ? ($previous->absolute_position ?: $previous->position) : null;
                $positionDelta = $previousPosition && $latestPosition ? $previousPosition - $latestPosition : null;

                return [
                    'title' => $latest->query,
                    'page' => $this->pageTitle($latest->page),
                    'latest_position' => $latestPosition,
                    'previous_position' => $previousPosition,
                    'position_delta' => $positionDelta,
                    'visibility_score' => round((float) $latest->visibility_score, 2),
                    'direction' => $positionDelta === null ? 'observed' : ($positionDelta >= 0 ? 'gain' : 'loss'),
                    'source_model' => PageSerpObservation::class,
                    'source_id' => $latest->id,
                    'page_id' => $latest->monitored_page_id,
                    'evidence' => $this->pageEvidence($latest->page, PageSerpObservation::class, $latest->id),
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $row): float => abs((float) ($row['position_delta'] ?? 0)) + (float) $row['visibility_score'] / 100)
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function geoMovements(array $context): array
    {
        $observations = PageGeoObservation::query()
            ->where('workspace_id', $context['workspace']->id)
            ->whereBetween('observed_at', [$context['period_start'], $context['period_end']])
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with('page:id,title_current,canonical_url,domain')
            ->orderByDesc('observed_at')
            ->limit(self::MOVEMENT_QUERY_LIMIT)
            ->get()
            ->sortBy('observed_at');

        return $observations
            ->groupBy(fn (PageGeoObservation $observation): string => implode('|', [
                $observation->query_hash ?: md5((string) $observation->query),
                $observation->monitored_page_id ?: $observation->cited_domain,
                $observation->answer_engine,
                $observation->provider,
            ]))
            ->map(function (Collection $group): ?array {
                /** @var PageGeoObservation|null $latest */
                $latest = $group->last();
                /** @var PageGeoObservation|null $previous */
                $previous = $group->count() > 1 ? $group->slice(-2, 1)->first() : null;
                if (! $latest) {
                    return null;
                }

                $scoreDelta = $previous ? (float) $latest->geo_visibility_score - (float) $previous->geo_visibility_score : null;

                return [
                    'title' => $latest->query,
                    'page' => $this->pageTitle($latest->page) ?: $latest->cited_domain,
                    'engine' => $latest->answer_engine,
                    'provider' => $latest->provider,
                    'client_cited' => (bool) $latest->client_cited,
                    'competitors_cited' => (bool) $latest->competitors_cited,
                    'visibility_score' => round((float) $latest->geo_visibility_score, 2),
                    'previous_visibility_score' => $previous ? round((float) $previous->geo_visibility_score, 2) : null,
                    'score_delta' => $scoreDelta !== null ? round($scoreDelta, 2) : null,
                    'direction' => $scoreDelta === null ? 'observed' : ($scoreDelta >= 0 ? 'gain' : 'loss'),
                    'source_model' => PageGeoObservation::class,
                    'source_id' => $latest->id,
                    'page_id' => $latest->monitored_page_id,
                    'evidence' => $this->pageEvidence($latest->page, PageGeoObservation::class, $latest->id),
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $row): float => abs((float) ($row['score_delta'] ?? 0)) + (float) $row['visibility_score'] / 100)
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function highestPrValuePages(array $context): array
    {
        return PagePrValue::query()
            ->where('workspace_id', $context['workspace']->id)
            ->whereBetween('calculated_at', [$context['period_start'], $context['period_end']])
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with('page:id,title_current,canonical_url,domain')
            ->orderByDesc('estimated_value_amount')
            ->orderByDesc('score')
            ->limit(8)
            ->get()
            ->map(fn (PagePrValue $value): array => [
                'title' => $this->pageTitle($value->page),
                'score' => round((float) $value->score, 2),
                'estimated_value_amount' => round((float) $value->estimated_value_amount, 2),
                'currency' => $value->currency,
                'confidence' => round((float) $value->confidence, 2),
                'source_model' => PagePrValue::class,
                'source_id' => $value->id,
                'page_id' => $value->monitored_page_id,
                'evidence' => $this->pageEvidence($value->page, PagePrValue::class, $value->id),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function marketPackSummary(array $context): array
    {
        $marketPack = $context['market_pack'] ?? null;
        if (! $marketPack instanceof MarketPack) {
            return [
                'active' => false,
                'summary' => 'No market pack filter was applied to this report.',
                'matched_pages' => 0,
            ];
        }

        $matchedPages = MonitoredPage::query()
            ->where('workspace_id', $context['workspace']->id)
            ->whereHas('marketPackMatches', fn (Builder $query): Builder => $query->where('market_pack_key', $marketPack->key))
            ->count();

        return [
            'active' => true,
            'key' => $marketPack->key,
            'name' => $marketPack->name,
            'category' => $marketPack->market_category,
            'summary' => 'This report is scoped to '.$marketPack->name.' market-pack evidence.',
            'matched_pages' => $matchedPages,
            'themes' => $marketPack->themes()->orderByDesc('weight')->limit(6)->pluck('name')->values()->all(),
            'competitors' => $marketPack->competitors()->orderBy('name')->limit(6)->pluck('name')->values()->all(),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function campaignImpact(array $context): array
    {
        $matches = PageCampaignMatch::query()
            ->where('workspace_id', $context['workspace']->id)
            ->whereBetween('observed_at', [$context['period_start'], $context['period_end']])
            ->when($context['campaign_id'], fn (Builder $query): Builder => $query->where('campaign_id', $context['campaign_id']))
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->wherePageInMarketPack($query, (string) $context['market_pack_key']))
            ->with(['page:id,title_current,canonical_url,domain', 'campaign:id,name'])
            ->orderByDesc('match_score')
            ->limit(8)
            ->get();

        return $matches->map(fn (PageCampaignMatch $match): array => [
            'title' => ($match->campaign?->name ?: 'Campaign').' matched '.$this->pageTitle($match->page),
            'campaign' => $match->campaign?->name,
            'match_type' => $match->match_type,
            'match_score' => round((float) $match->match_score, 2),
            'source_model' => PageCampaignMatch::class,
            'source_id' => $match->id,
            'page_id' => $match->monitored_page_id,
            'evidence' => $this->pageEvidence($match->page, PageCampaignMatch::class, $match->id),
        ])->values()->all();
    }

    /**
     * @param array<string,array<int|string,mixed>> $sections
     * @return array<int,array<string,mixed>>
     */
    private function recommendedActions(array $sections): array
    {
        $actions = [];

        foreach (array_slice($sections['top_risks'] ?? [], 0, 3) as $risk) {
            $actions[] = [
                'title' => 'Address risk: '.$risk['title'],
                'priority' => 'high',
                'rationale' => $risk['summary'] ?? 'Risk detected in monitoring data.',
                'evidence' => $risk['evidence'] ?? null,
            ];
        }

        foreach (array_slice($sections['serp_movements'] ?? [], 0, 2) as $movement) {
            if (($movement['direction'] ?? '') === 'loss') {
                $actions[] = [
                    'title' => 'Recover SERP visibility for '.$movement['title'],
                    'priority' => 'medium',
                    'rationale' => 'Ranking position declined during the report period.',
                    'evidence' => $movement['evidence'] ?? null,
                ];
            }
        }

        foreach (array_slice($sections['geo_ai_visibility_movements'] ?? [], 0, 2) as $movement) {
            if (! (bool) ($movement['client_cited'] ?? false)) {
                $actions[] = [
                    'title' => 'Improve AI citation coverage for '.$movement['title'],
                    'priority' => 'medium',
                    'rationale' => 'Answer-engine evidence did not cite the client page.',
                    'evidence' => $movement['evidence'] ?? null,
                ];
            }
        }

        foreach (array_slice($sections['highest_pr_value_pages'] ?? [], 0, 2) as $page) {
            $actions[] = [
                'title' => 'Amplify high-value page: '.$page['title'],
                'priority' => 'low',
                'rationale' => 'High estimated PR value can support customer briefing and campaign proof.',
                'evidence' => $page['evidence'] ?? null,
            ];
        }

        if ($actions === []) {
            $actions[] = [
                'title' => 'Continue monitoring and prepare the next briefing',
                'priority' => 'low',
                'rationale' => 'No urgent movement was detected in this reporting window.',
                'evidence' => null,
            ];
        }

        return array_values(array_slice($actions, 0, 8));
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $sections
     * @return array<string,mixed>
     */
    private function executiveSummary(Workspace $workspace, array $context, array $sections): array
    {
        $pageCount = MonitoredPage::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('last_seen_at', [$context['period_start'], $context['period_end']])
            ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->whereMonitoredPageInMarketPack($query, (string) $context['market_pack_key']))
            ->count();

        $signalCount = SignalEvent::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('observed_at', [$context['period_start'], $context['period_end']])
            ->count();

        $market = $context['market_pack'] instanceof MarketPack ? ' for '.$context['market_pack']->name : '';
        $narrative = sprintf(
            '%s summarizes %d monitored page(s)%s, %d signal event(s), %d opportunity item(s), %d risk item(s), %d SERP movement(s), and %d GEO/AI movement(s).',
            $context['template']['label'],
            $pageCount,
            $market,
            $signalCount,
            count($sections['top_opportunities'] ?? []),
            count($sections['top_risks'] ?? []),
            count($sections['serp_movements'] ?? []),
            count($sections['geo_ai_visibility_movements'] ?? []),
        );

        return [
            'headline' => $context['template']['label'],
            'narrative' => $narrative,
            'metrics' => [
                'monitored_pages' => $pageCount,
                'signal_events' => $signalCount,
                'opportunities' => count($sections['top_opportunities'] ?? []),
                'risks' => count($sections['top_risks'] ?? []),
                'competitor_movements' => count($sections['competitor_movements'] ?? []),
                'serp_movements' => count($sections['serp_movements'] ?? []),
                'geo_movements' => count($sections['geo_ai_visibility_movements'] ?? []),
                'highest_pr_value_pages' => count($sections['highest_pr_value_pages'] ?? []),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<int,array<string,mixed>>
     */
    private function evidenceLinks(array $sections): array
    {
        return collect($sections)
            ->except(['executive_summary', 'recommended_actions', 'market_pack_summary'])
            ->flatMap(fn ($rows) => is_array($rows) ? $rows : [])
            ->map(fn ($row) => is_array($row) ? ($row['evidence'] ?? null) : null)
            ->filter(fn ($evidence): bool => is_array($evidence) && ! empty($evidence['url']))
            ->unique(fn (array $evidence): string => (string) ($evidence['source_model'] ?? '').'|'.(string) ($evidence['source_id'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $sections
     * @return array<string,mixed>
     */
    private function provenance(Workspace $workspace, array $context, array $sections): array
    {
        return [
            'workspace_id' => $workspace->id,
            'organization_id' => $workspace->organization_id,
            'template_version' => self::TEMPLATE_VERSION,
            'generated_from_existing_data_only' => true,
            'direct_fetching' => false,
            'period_start' => $context['period_start']->toIso8601String(),
            'period_end' => $context['period_end']->toIso8601String(),
            'market_pack_key' => $context['market_pack_key'],
            'client_site_id' => $context['client_site_id'],
            'query_parameters' => $context['query_parameters'],
            'scorer_versions' => $this->scorerVersions($sections),
            'source_tables' => [
                'monitored_pages' => MonitoredPage::query()->where('workspace_id', $workspace->id)->count(),
                'page_scores' => PageScore::query()->where('workspace_id', $workspace->id)->count(),
                'page_pr_values' => PagePrValue::query()->where('workspace_id', $workspace->id)->count(),
                'page_sentiments' => PageSentiment::query()->where('workspace_id', $workspace->id)->count(),
                'page_serp_observations' => PageSerpObservation::query()->where('workspace_id', $workspace->id)->count(),
                'page_geo_observations' => PageGeoObservation::query()->where('workspace_id', $workspace->id)->count(),
                'page_alerts' => PageAlert::query()->where('workspace_id', $workspace->id)->count(),
                'signal_events' => SignalEvent::query()->where('workspace_id', $workspace->id)->count(),
                'site_competitors' => SiteCompetitor::query()->where('workspace_id', $workspace->id)->count(),
                'campaigns' => Campaign::query()->where('workspace_id', $workspace->id)->count(),
            ],
            'source_row_ids' => $this->sourceRowIds($workspace, $context, $sections),
            'section_item_counts' => collect($sections)->map(fn ($section) => is_countable($section) ? count($section) : 0)->all(),
        ];
    }

    private function wherePageInMarketPack(Builder $query, string $marketPackKey): Builder
    {
        return $query->whereHas('page', fn (Builder $page): Builder => $page->whereHas(
            'marketPackMatches',
            fn (Builder $match): Builder => $match->where('market_pack_key', $marketPackKey)
        ));
    }

    private function whereMonitoredPageInMarketPack(Builder $query, string $marketPackKey): Builder
    {
        return $query->whereHas(
            'marketPackMatches',
            fn (Builder $match): Builder => $match->where('market_pack_key', $marketPackKey)
        );
    }

    private function pageEvidence(?MonitoredPage $page, string $sourceModel, string $sourceId): ?array
    {
        if (! $page) {
            return null;
        }

        return [
            'label' => $this->pageTitle($page),
            'type' => 'monitored_page',
            'url' => route('app.page-intelligence.monitored-pages.show', $page),
            'canonical_url' => $page->canonical_url,
            'source_model' => $sourceModel,
            'source_id' => $sourceId,
            'page_id' => $page->id,
        ];
    }

    private function pageTitle(?MonitoredPage $page): string
    {
        return trim((string) ($page?->title_current ?: $page?->domain ?: $page?->canonical_url ?: 'Untitled page'));
    }

    private function title(string $label, Carbon $periodStart, Carbon $periodEnd, ?MarketPack $marketPack): string
    {
        $market = $marketPack ? $marketPack->name.' - ' : '';

        return $market.$label.' · '.$periodStart->toDateString().' to '.$periodEnd->toDateString();
    }

    /**
     * @param array<string,mixed> $options
     */
    private function assertTenantScope(Workspace $workspace, array $options, ?User $user): void
    {
        if ($user instanceof User && (int) $user->organization_id !== (int) $workspace->organization_id) {
            throw new AuthorizationException('Workspace is not available for this user.');
        }

        $clientSiteId = trim((string) ($options['client_site_id'] ?? '')) ?: null;
        if ($clientSiteId === null) {
            return;
        }

        $siteExists = ClientSite::query()
            ->whereKey($clientSiteId)
            ->where('workspace_id', $workspace->id)
            ->exists();

        if (! $siteExists) {
            throw new InvalidArgumentException('Client site is not installed in the selected workspace.');
        }
    }

    private function resolveInstalledMarketPack(Workspace $workspace, ?string $marketPackKey, ?string $clientSiteId): ?MarketPack
    {
        if ($marketPackKey === null) {
            return null;
        }

        $installation = MarketPackInstallation::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', MarketPackInstallation::STATUS_ACTIVE)
            ->when($clientSiteId !== null, function (Builder $query) use ($clientSiteId): Builder {
                return $query->where(function (Builder $site) use ($clientSiteId): void {
                    $site->whereNull('client_site_id')
                        ->orWhere('client_site_id', $clientSiteId);
                });
            })
            ->whereHas('marketPack', fn (Builder $query): Builder => $query->where('key', $marketPackKey))
            ->with('marketPack')
            ->first();

        if (! $installation?->marketPack instanceof MarketPack) {
            throw new InvalidArgumentException("Market pack [{$marketPackKey}] is not installed for this workspace or site.");
        }

        return $installation->marketPack;
    }

    /**
     * @return array{hash:string,parts:array<string,mixed>}
     */
    private function reportIdentity(Workspace $workspace, string $reportType, Carbon $periodStart, Carbon $periodEnd, ?string $marketPackKey, ?string $clientSiteId): array
    {
        $parts = [
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSiteId,
            'report_type' => $reportType,
            'period_start' => $periodStart->toIso8601String(),
            'period_end' => $periodEnd->toIso8601String(),
            'market_pack_key' => $marketPackKey,
        ];

        return [
            'hash' => hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR)),
            'parts' => $parts,
        ];
    }

    /**
     * @param array{hash:string,parts:array<string,mixed>} $identity
     * @param array<string,mixed> $options
     */
    private function idempotencyKey(array $identity, array $options): ?string
    {
        $requestKey = trim((string) ($options['idempotency_key'] ?? $options['request_key'] ?? ''));

        if ($requestKey === '') {
            return null;
        }

        return hash('sha256', $identity['hash'].'|'.$requestKey);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function queryParameters(array $options): array
    {
        return Arr::only($options, [
            'client_site_id',
            'market_pack_key',
            'market_pack',
            'campaign_id',
            'period_start',
            'period_end',
            'request_key',
            'idempotency_key',
        ]);
    }

    private function allocateSnapshotVersion(
        Workspace $workspace,
        string $reportType,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?string $marketPackKey,
        ?string $clientSiteId,
        string $identityHash,
    ): int {
        $allocation = PageIntelligenceReportSnapshotAllocation::query()
            ->where('identity_hash', $identityHash)
            ->lockForUpdate()
            ->first();

        if (! $allocation instanceof PageIntelligenceReportSnapshotAllocation) {
            try {
                $allocation = PageIntelligenceReportSnapshotAllocation::query()->create([
                    'organization_id' => $workspace->organization_id,
                    'workspace_id' => $workspace->id,
                    'client_site_id' => $clientSiteId,
                    'report_type' => $reportType,
                    'market_pack_key' => $marketPackKey,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'identity_hash' => $identityHash,
                    'current_version' => 0,
                ]);
            } catch (QueryException) {
                $allocation = PageIntelligenceReportSnapshotAllocation::query()
                    ->where('identity_hash', $identityHash)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
        }

        $allocation->current_version++;
        $allocation->save();

        return (int) $allocation->current_version;
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<string,array<int,string>>
     */
    private function scorerVersions(array $sections): array
    {
        $scoreIds = $this->sourceIdsFor(PageScore::class, $sections);
        $prValueIds = $this->sourceIdsFor(PagePrValue::class, $sections);

        return [
            'page_scores' => $scoreIds === [] ? [] : PageScore::query()
                ->whereIn('id', $scoreIds)
                ->pluck('score_version')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'page_pr_values' => $prValueIds === [] ? [] : PagePrValue::query()
                ->whereIn('id', $prValueIds)
                ->pluck('model_version')
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $sections
     * @return array<string,array<int,string>>
     */
    private function sourceRowIds(Workspace $workspace, array $context, array $sections): array
    {
        $ids = [
            'monitored_pages' => collect($sections)
                ->flatMap(fn ($section) => is_array($section) ? $section : [])
                ->filter(fn ($row): bool => is_array($row))
                ->pluck('page_id')
                ->filter()
                ->merge(MonitoredPage::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereBetween('last_seen_at', [$context['period_start'], $context['period_end']])
                    ->when($context['market_pack_key'], fn (Builder $query): Builder => $this->whereMonitoredPageInMarketPack($query, (string) $context['market_pack_key']))
                    ->pluck('id'))
                ->unique()
                ->values()
                ->all(),
            'page_scores' => $this->sourceIdsFor(PageScore::class, $sections),
            'page_pr_values' => $this->sourceIdsFor(PagePrValue::class, $sections),
            'page_sentiments' => $this->sourceIdsFor(PageSentiment::class, $sections),
            'page_serp_observations' => $this->sourceIdsFor(PageSerpObservation::class, $sections),
            'page_geo_observations' => $this->sourceIdsFor(PageGeoObservation::class, $sections),
            'page_alerts' => $this->sourceIdsFor(PageAlert::class, $sections),
            'signal_events' => SignalEvent::query()
                ->where('workspace_id', $workspace->id)
                ->whereBetween('observed_at', [$context['period_start'], $context['period_end']])
                ->pluck('id')
                ->values()
                ->all(),
        ];

        $snapshotIds = collect([
            PageScore::class => $ids['page_scores'],
            PagePrValue::class => $ids['page_pr_values'],
            PageSerpObservation::class => $ids['page_serp_observations'],
            PageGeoObservation::class => $ids['page_geo_observations'],
            PageAlert::class => $ids['page_alerts'],
            PageSentiment::class => $this->sourceIdsFor(PageSentiment::class, $sections),
        ])->flatMap(fn (array $rowIds, string $model): array => $rowIds === [] ? [] : $model::query()
            ->whereIn('id', $rowIds)
            ->pluck('page_snapshot_id')
            ->filter()
            ->values()
            ->all());

        $ids['page_snapshots'] = $snapshotIds
            ->merge(PageSnapshot::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('monitored_page_id', $ids['monitored_pages'])
                ->whereBetween('fetched_at', [$context['period_start'], $context['period_end']])
                ->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $ids['page_content_extractions'] = PageContentExtraction::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('page_snapshot_id', $ids['page_snapshots'])
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<int,string>
     */
    private function sourceIdsFor(string $model, array $sections): array
    {
        return collect($sections)
            ->flatMap(fn ($section) => is_array($section) ? $section : [])
            ->filter(fn ($row): bool => is_array($row) && ($row['source_model'] ?? null) === $model && ! empty($row['source_id']))
            ->pluck('source_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $provenance
     */
    private function dataFingerprint(array $provenance): string
    {
        return hash('sha256', json_encode([
            'template_version' => $provenance['template_version'],
            'period_start' => $provenance['period_start'],
            'period_end' => $provenance['period_end'],
            'market_pack_key' => $provenance['market_pack_key'],
            'client_site_id' => $provenance['client_site_id'],
            'source_row_ids' => $provenance['source_row_ids'],
            'scorer_versions' => $provenance['scorer_versions'],
            'query_parameters' => $provenance['query_parameters'],
        ], JSON_THROW_ON_ERROR));
    }
}
