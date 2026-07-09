<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\MarketingObservation;
use App\Models\MarketingOperatingLink;
use App\Models\PageIntelligenceReport;
use App\Models\RecommendedAction;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\Workspace;
use App\Services\AgenticMarketing\Intelligence\MarketingReasoningEngine;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;
use App\Services\AgenticMarketing\Intelligence\ReasoningSnapshot;
use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use App\Services\PerformanceIntelligence\PerformanceSignal;
use App\Services\PerformanceIntelligence\PerformanceSnapshot;
use App\Support\Intelligence\ReasoningResult;
use App\Support\Intelligence\TimeWindow;
use App\Support\Intelligence\TimeWindowPreset;
use App\Support\Intelligence\TimeWindowResolver;
use App\Support\Interaction\DrawerMetadataBuilder;
use App\Support\Interaction\DrawerState;
use App\Support\Interaction\DrawerTarget;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class AppMarketingIntelligenceWorkspaceController extends Controller
{
    public function index(
        Request $request,
        PerformanceIntelligenceEngine $performance,
        MarketingReasoningEngine $reasoning,
        TimeWindowResolver $timeWindows,
    ): View {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace instanceof Workspace, 404);

        $this->authorize('viewContentIntelligence', $workspace);

        $clientSite = $this->resolveClientSite($request, $workspace);
        $selection = $this->resolveTimeWindow($request, $timeWindows, $workspace, $clientSite);
        /** @var TimeWindow $window */
        $window = $selection['window'];

        $snapshot = $performance->snapshot(
            $workspace,
            $clientSite,
            $window->start,
            $window->end,
            MarketingObservation::GRANULARITY_DAILY,
        );
        $signals = $this->rankedSignals($snapshot);
        $reasoningSnapshot = $snapshot->observationsCount > 0
            ? $reasoning->reason($workspace, $clientSite, [
                'from' => $window->start,
                'to' => $window->end,
                'granularity' => MarketingObservation::GRANULARITY_DAILY,
            ])
            : null;
        $reasoningResults = $reasoningSnapshot instanceof ReasoningSnapshot
            ? collect($reasoningSnapshot->reasoningResults())
            : collect();
        $operatingLinks = MarketingOperatingLink::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->limit(8)
            ->get();

        $trendCards = $signals
            ->map(fn (PerformanceSignal $signal): array => $this->trendCard($signal))
            ->take(8)
            ->values();
        $riskCards = $signals
            ->filter(fn (PerformanceSignal $signal): bool => $this->isRiskSignal($signal))
            ->map(fn (PerformanceSignal $signal): array => $this->impactCard($signal, 'Risk'))
            ->take(4)
            ->values();
        $opportunityCards = $signals
            ->filter(fn (PerformanceSignal $signal): bool => ! $this->isRiskSignal($signal) && $signal->direction === 'growth')
            ->map(fn (PerformanceSignal $signal): array => $this->impactCard($signal, 'Opportunity'))
            ->take(4)
            ->values();
        $reports = $this->reports($request, $workspace, $clientSite, $window);
        $briefings = $this->briefings($request, $workspace, $clientSite);
        $recommendations = $this->recommendations($workspace, $reasoningSnapshot);
        $evidence = $this->evidenceSummary($snapshot, $reasoningResults, $operatingLinks, $reports, $briefings);

        return view('app.marketing-intelligence.index', [
            'title' => 'Unified Marketing Intelligence Workspace',
            'workspace' => $workspace,
            'workspaces' => $this->availableWorkspaces($request),
            'clientSite' => $clientSite,
            'clientSites' => $this->availableClientSites($workspace),
            'timeWindow' => $window,
            'timeWindowSelection' => $selection,
            'snapshot' => $snapshot,
            'summary' => [
                'trends' => $trendCards->count(),
                'risks' => $riskCards->count(),
                'opportunities' => $opportunityCards->count(),
                'recommendations' => $recommendations->count(),
            ],
            'trendCards' => $trendCards,
            'riskCards' => $riskCards,
            'opportunityCards' => $opportunityCards,
            'recommendations' => $recommendations,
            'reports' => $reports,
            'briefings' => $briefings,
            'operatingLinks' => $operatingLinks,
            'reasoningResults' => $reasoningResults,
            'evidence' => $evidence,
            'hasWorkspaceData' => $snapshot->observationsCount > 0
                || $recommendations->isNotEmpty()
                || $reports->isNotEmpty()
                || $briefings->isNotEmpty(),
        ]);
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $user = $request->user();
        $organizationId = $user?->organization_id;
        $workspaceId = (string) ($request->query('workspace') ?: $request->query('workspace_id') ?: '');

        if (($user?->is_admin ?? false) && $workspaceId !== '') {
            return Workspace::query()->whereKey($workspaceId)->first();
        }

        $query = Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at');

        if ($workspaceId !== '') {
            return (clone $query)->whereKey($workspaceId)->first();
        }

        return $query->first();
    }

    private function resolveClientSite(Request $request, Workspace $workspace): ?ClientSite
    {
        $siteId = (string) ($request->query('site') ?: $request->query('client_site_id') ?: '');

        if ($siteId === '') {
            return null;
        }

        $site = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($siteId)
            ->first();

        abort_unless($site instanceof ClientSite, 404);

        return $site;
    }

    /**
     * @return array{preset:string,label:string,window:TimeWindow,options:array<int,array{value:string,label:string}>,from:?string,to:?string,is_custom_range:bool,uses_to_date:bool,filter_notice:?string}
     */
    private function resolveTimeWindow(Request $request, TimeWindowResolver $resolver, Workspace $workspace, ?ClientSite $clientSite): array
    {
        $preset = TimeWindowPreset::normalize($request->query('time_window', TimeWindowPreset::LAST_7_DAYS->value), TimeWindowPreset::LAST_7_DAYS);
        $filterNotice = null;
        $from = $this->dateInput($request->query('from'), $filterNotice, 'From');
        $to = $this->dateInput($request->query('to'), $filterNotice, 'To');

        if ($preset === TimeWindowPreset::CUSTOM_RANGE) {
            $from = $from ?: now()->subDays(6)->toDateString();
            $to = $to ?: now()->toDateString();

            if ($this->dateIsAfter($from, $to)) {
                [$from, $to] = [$to, $from];
                $filterNotice = 'The timeframe was adjusted so From is before To.';
            }
        }

        $options = [
            'granularity' => MarketingObservation::GRANULARITY_DAILY,
            'to' => $to ?: now(),
        ];

        if ($preset === TimeWindowPreset::CUSTOM_RANGE) {
            $options['from'] = $from;
        }

        try {
            $window = $resolver->resolve($preset, $options, $workspace, $clientSite);
        } catch (Throwable) {
            $preset = TimeWindowPreset::LAST_7_DAYS;
            $from = null;
            $to = null;
            $filterNotice = 'The timeframe filter could not be applied, so the last 7 days are shown.';
            $window = $resolver->resolve($preset, [
                'granularity' => MarketingObservation::GRANULARITY_DAILY,
                'to' => now(),
            ], $workspace, $clientSite);
        }

        return [
            'preset' => $preset->value,
            'label' => $this->timeWindowLabel($preset),
            'window' => $window,
            'options' => [
                ['value' => TimeWindowPreset::LAST_7_DAYS->value, 'label' => 'Last 7 days'],
                ['value' => TimeWindowPreset::LAST_28_DAYS->value, 'label' => 'Last 28 days'],
                ['value' => TimeWindowPreset::YESTERDAY->value, 'label' => 'Yesterday'],
                ['value' => TimeWindowPreset::TODAY->value, 'label' => 'Today'],
                ['value' => TimeWindowPreset::CUSTOM_RANGE->value, 'label' => 'Custom range'],
            ],
            'from' => $from,
            'to' => $to,
            'is_custom_range' => $preset === TimeWindowPreset::CUSTOM_RANGE,
            'uses_to_date' => in_array($preset, [TimeWindowPreset::LAST_7_DAYS, TimeWindowPreset::LAST_28_DAYS, TimeWindowPreset::CUSTOM_RANGE], true),
            'filter_notice' => $filterNotice,
        ];
    }

    /**
     * @return Collection<int,Workspace>
     */
    private function availableWorkspaces(Request $request): Collection
    {
        return Workspace::query()
            ->where('organization_id', $request->user()?->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'display_name', 'organization_id']);
    }

    /**
     * @return Collection<int,ClientSite>
     */
    private function availableClientSites(Workspace $workspace): Collection
    {
        return ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['id', 'workspace_id', 'name', 'site_url', 'base_url']);
    }

    /**
     * @return Collection<int,PerformanceSignal>
     */
    private function rankedSignals(PerformanceSnapshot $snapshot): Collection
    {
        return collect($snapshot->signals)
            ->sortByDesc(function (PerformanceSignal $signal): float {
                $growth = abs((float) ($signal->metadata['growth_percent'] ?? 0));

                return $growth * max(0.1, $signal->confidence);
            })
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function trendCard(PerformanceSignal $signal): array
    {
        $growth = $signal->metadata['growth_percent'] ?? null;
        $current = $signal->metadata['current_value'] ?? null;
        $previous = $signal->metadata['previous_value'] ?? null;
        $descriptor = $this->drawerDescriptorForSignal($signal);

        return [
            'key' => $signal->key,
            'kind' => 'Trend',
            'title' => $this->signalTitle($signal),
            'direction' => Str::headline($signal->direction),
            'metric' => $this->humanMetric($signal->metricKey),
            'change' => is_numeric($growth) ? number_format((float) $growth, 1).'%' : 'Changed',
            'current' => is_numeric($current) ? number_format((float) $current, 2) : 'n/a',
            'previous' => is_numeric($previous) ? number_format((float) $previous, 2) : 'n/a',
            'timeframe' => $this->timeframe($signal->timeWindow()),
            'confidence' => $this->confidenceLabel($signal->confidence),
            'impact' => $this->impactLabel($signal),
            'source' => 'Marketing observations',
            'evidence_count' => count($signal->observationIds),
            'explanation' => $signal->explanation,
            'tone' => $this->signalTone($signal),
            'descriptor' => $descriptor,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function impactCard(PerformanceSignal $signal, string $kind): array
    {
        return [
            'key' => $signal->key,
            'kind' => $kind,
            'title' => $this->signalTitle($signal),
            'confidence' => $this->confidenceLabel($signal->confidence),
            'impact' => $this->impactLabel($signal),
            'timeframe' => $this->timeframe($signal->timeWindow()),
            'source' => 'Marketing observations',
            'evidence_count' => count($signal->observationIds),
            'summary' => $signal->explanation,
            'next_action' => $kind === 'Risk'
                ? 'Review the evidence and choose whether this needs mitigation.'
                : 'Review the evidence and choose whether to expand this momentum.',
            'tone' => $this->signalTone($signal),
            'descriptor' => $this->drawerDescriptorForSignal($signal),
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function recommendations(Workspace $workspace, ?ReasoningSnapshot $reasoningSnapshot): Collection
    {
        $stored = RecommendedAction::query()
            ->forWorkspace($workspace)
            ->visible()
            ->whereIn('status', [
                RecommendedAction::STATUS_OPEN,
                RecommendedAction::STATUS_APPROVED,
                RecommendedAction::STATUS_IN_PROGRESS,
            ])
            ->orderByDesc('priority_score')
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (RecommendedAction $action): array => [
                'key' => (string) $action->id,
                'source' => 'Recommended action',
                'title' => $action->title,
                'summary' => $action->summary ?: $action->why_this_matters,
                'next_action' => $action->what_argusly_will_do ?: $action->primary_cta_label ?: 'Review the recommendation.',
                'impact' => Str::headline((string) ($action->expected_impact_label ?: 'medium')),
                'confidence' => Str::headline((string) ($action->confidence_label ?: 'medium')),
                'priority' => (int) $action->priority_score,
                'url' => $action->primary_cta_url ?: route('app.recommended-actions.index'),
            ]);

        $reasoned = collect($reasoningSnapshot?->recommendations ?? [])
            ->map(fn (MarketingRecommendation $recommendation): array => [
                'key' => $recommendation->key,
                'source' => 'Evidence-based recommendation',
                'title' => $recommendation->title,
                'summary' => $recommendation->summary,
                'next_action' => (string) collect($recommendation->recommendedActions)->first() ?: 'Review the recommendation evidence.',
                'impact' => $this->scoreLabel($recommendation->priority),
                'confidence' => $this->confidenceLabel($recommendation->confidence),
                'priority' => $recommendation->priority,
                'url' => null,
            ]);

        return $stored
            ->concat($reasoned)
            ->unique('key')
            ->sortByDesc('priority')
            ->take(6)
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function reports(Request $request, Workspace $workspace, ?ClientSite $clientSite, TimeWindow $window): Collection
    {
        return PageIntelligenceReport::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn ($query): mixed => $query->where('client_site_id', $clientSite->id))
            ->where('period_start', '<=', $window->end)
            ->where('period_end', '>=', $window->start)
            ->latest('generated_at')
            ->limit(6)
            ->get()
            ->filter(fn (PageIntelligenceReport $report): bool => $request->user()?->can('view', $report) ?? false)
            ->map(fn (PageIntelligenceReport $report): array => [
                'id' => (string) $report->id,
                'title' => $report->title,
                'summary' => $report->summary,
                'timeframe' => $report->period_start && $report->period_end
                    ? $report->period_start->toDateString().' to '.$report->period_end->toDateString()
                    : 'No timeframe',
                'status' => Str::headline((string) $report->status),
                'url' => route('app.page-intelligence.reports.show', $report),
            ])
            ->values();
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function briefings(Request $request, Workspace $workspace, ?ClientSite $clientSite): Collection
    {
        return ScheduledPageIntelligenceBriefing::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSite, fn ($query): mixed => $query->where(function ($query) use ($clientSite): void {
                $query->whereNull('client_site_id')->orWhere('client_site_id', $clientSite->id);
            }))
            ->orderBy('next_run_at')
            ->limit(6)
            ->get()
            ->filter(fn (ScheduledPageIntelligenceBriefing $briefing): bool => $request->user()?->can('view', $briefing) ?? false)
            ->map(fn (ScheduledPageIntelligenceBriefing $briefing): array => [
                'id' => (string) $briefing->id,
                'title' => Str::headline((string) $briefing->report_type),
                'frequency' => Str::headline((string) $briefing->frequency),
                'timeframe' => $briefing->next_run_at?->toDateString() ?: 'Not scheduled',
                'status' => $briefing->is_active ? 'Active' : 'Inactive',
                'url' => route('app.page-intelligence.scheduled-briefings.edit', $briefing),
            ])
            ->values();
    }

    /**
     * @param  Collection<int,ReasoningResult>  $reasoningResults
     * @param  Collection<int,MarketingOperatingLink>  $operatingLinks
     * @param  Collection<int,array<string,mixed>>  $reports
     * @param  Collection<int,array<string,mixed>>  $briefings
     * @return array<string,mixed>
     */
    private function evidenceSummary(
        PerformanceSnapshot $snapshot,
        Collection $reasoningResults,
        Collection $operatingLinks,
        Collection $reports,
        Collection $briefings,
    ): array {
        $snapshotEvidence = $snapshot->evidenceBag();
        $operatingGraphEdges = $operatingLinks
            ->map(fn (MarketingOperatingLink $link): array => $link->toIntelligenceGraphEdge()->toArray())
            ->values();
        $reasoningGraphEdges = $reasoningResults
            ->flatMap(fn (ReasoningResult $result): array => $result->graphEdges())
            ->map(fn ($edge): array => $edge->toArray())
            ->values();

        return [
            'source' => 'Marketing observations',
            'timeframe' => $this->timeframe($snapshot->timeWindow()),
            'observations_count' => $snapshot->observationsCount,
            'reference_count' => count($snapshotEvidence->references),
            'source_metric_count' => count($snapshotEvidence->sourceMetrics),
            'reasoning_result_count' => $reasoningResults->count(),
            'graph_edge_count' => $operatingGraphEdges->count() + $reasoningGraphEdges->count(),
            'report_count' => $reports->count(),
            'briefing_count' => $briefings->count(),
            'operating_link_count' => $operatingLinks->count(),
            'metadata' => [
                'read_only' => true,
                'storage_mutated' => false,
                'source_read_model' => 'PerformanceSnapshot',
                'snapshot_workspace_id' => $snapshot->workspaceId,
                'snapshot_client_site_id' => $snapshot->clientSiteId,
            ],
        ];
    }

    private function drawerDescriptorForSignal(PerformanceSignal $signal): array
    {
        return DrawerMetadataBuilder::make()->build(
            DrawerTarget::make('marketing-intelligence.evidence', DrawerState::MODE_INSPECT, 'lg')
                ->forResource('performance_signal', $signal->key, $signal->key)
                ->forAction('app.marketing-intelligence.evidence')
                ->withHref('#evidence-'.Str::slug($signal->key)),
            [
                'title' => 'Evidence',
                'subtitle' => $this->signalTitle($signal),
                'icon' => 'panel-right-open',
                'badges' => [
                    ['label' => $this->impactLabel($signal).' impact', 'tone' => $this->signalTone($signal)],
                    ['label' => $this->confidenceLabel($signal->confidence).' confidence', 'tone' => 'neutral'],
                ],
                'tabs' => [],
                'sections' => [
                    [
                        'key' => 'trend',
                        'title' => 'Trend',
                        'items' => [
                            ['label' => 'Trend', 'value' => $this->signalTitle($signal)],
                            ['label' => 'Direction', 'value' => Str::headline($signal->direction)],
                            ['label' => 'Impact', 'value' => $this->impactLabel($signal)],
                            ['label' => 'Change', 'value' => is_numeric($signal->metadata['growth_percent'] ?? null) ? number_format((float) $signal->metadata['growth_percent'], 1).'%' : 'Changed'],
                        ],
                    ],
                    [
                        'key' => 'evidence',
                        'title' => 'Evidence',
                        'items' => [
                            ['label' => 'Source', 'value' => 'Marketing observations'],
                            ['label' => 'Timeframe', 'value' => $this->timeframe($signal->timeWindow())],
                            ['label' => 'Confidence', 'value' => $this->confidenceLabel($signal->confidence)],
                            ['label' => 'Evidence', 'value' => count($signal->observationIds).' observation'.(count($signal->observationIds) === 1 ? '' : 's')],
                            ['label' => 'Current value', 'value' => is_numeric($signal->metadata['current_value'] ?? null) ? number_format((float) $signal->metadata['current_value'], 2) : 'n/a'],
                            ['label' => 'Previous value', 'value' => is_numeric($signal->metadata['previous_value'] ?? null) ? number_format((float) $signal->metadata['previous_value'], 2) : 'n/a'],
                        ],
                    ],
                    [
                        'key' => 'next_action',
                        'title' => 'Next action',
                        'items' => [
                            ['label' => 'Recommendation', 'value' => $this->isRiskSignal($signal)
                                ? 'Review the evidence and choose whether this needs mitigation.'
                                : 'Review the evidence and choose whether to expand this momentum.'],
                            ['label' => 'Read-only', 'value' => 'No source data is changed from this workspace.'],
                        ],
                    ],
                ],
                'footer_actions' => [],
                'loading' => [
                    'title' => 'Loading evidence',
                    'description' => 'Preparing source, timeframe, confidence, and impact metadata.',
                ],
                'empty' => [
                    'title' => 'No evidence selected',
                    'description' => 'Choose Evidence on a trend, risk, or opportunity to inspect its source metadata.',
                ],
                'errors' => [
                    'title' => 'Unable to show evidence',
                    'description' => 'The evidence metadata could not be rendered safely.',
                ],
                'metadata' => [
                    'read_only' => true,
                    'source_read_model' => 'PerformanceSnapshot',
                    'signal_key' => $signal->key,
                    'evidence_reference_count' => count($signal->observationIds),
                    'renders_production_content' => false,
                ],
            ],
        )->toArray();
    }

    private function isRiskSignal(PerformanceSignal $signal): bool
    {
        return $signal->direction === 'decline' || str_contains((string) $signal->type, 'risk');
    }

    private function signalTone(PerformanceSignal $signal): string
    {
        return $this->isRiskSignal($signal) ? 'risk' : 'opportunity';
    }

    private function signalTitle(PerformanceSignal $signal): string
    {
        $subject = trim((string) ($signal->subjectName ?: $signal->subjectKey ?: 'Workspace'));

        return $subject.' '.$this->humanMetric($signal->metricKey);
    }

    private function humanMetric(string $metric): string
    {
        return Str::headline(str_replace(['_', '-'], ' ', $metric));
    }

    private function confidenceLabel(float $confidence): string
    {
        return number_format(max(0, min(1, $confidence)) * 100, 0).'%';
    }

    private function impactLabel(PerformanceSignal $signal): string
    {
        $growth = abs((float) ($signal->metadata['growth_percent'] ?? 0));
        $score = min(100, (int) round(($growth * 0.7) + ($signal->confidence * 30)));

        return $this->scoreLabel($score);
    }

    private function scoreLabel(int $score): string
    {
        return match (true) {
            $score >= 75 => 'High',
            $score >= 45 => 'Medium',
            default => 'Low',
        };
    }

    private function timeframe(TimeWindow $window): string
    {
        return $window->start->toDateString().' to '.$window->end->toDateString();
    }

    private function timeWindowLabel(TimeWindowPreset $preset): string
    {
        return match ($preset) {
            TimeWindowPreset::LAST_7_DAYS => 'Last 7 days',
            TimeWindowPreset::LAST_28_DAYS => 'Last 28 days',
            TimeWindowPreset::YESTERDAY => 'Yesterday',
            TimeWindowPreset::TODAY => 'Today',
            TimeWindowPreset::CUSTOM_RANGE => 'Custom range',
            default => Str::headline($preset->value),
        };
    }

    private function dateInput(mixed $value, ?string &$notice, string $label): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $notice = $label.' date was ignored because it was not a valid date.';

            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        if (! checkdate($month, $day, $year)) {
            $notice = $label.' date was ignored because it was not a valid date.';

            return null;
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') ?: null;
    }

    private function dateIsAfter(string $from, string $to): bool
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $from) > DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    }
}
