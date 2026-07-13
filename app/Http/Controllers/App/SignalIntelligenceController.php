<?php

namespace App\Http\Controllers\App;

use App\Enums\SignalStatus;
use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\Workspace;
use App\Services\Entitlements\FeatureGate;
use App\Services\Journey\FirstValueExperienceService;
use App\Services\Onboarding\FirstValueActivationService;
use App\Services\SignalIntelligence\BrandMonitoringDetectionService;
use App\Services\SignalIntelligence\CompetitorMonitoringDetectionService;
use App\Services\SignalIntelligence\LlmTrackingSignalAdapter;
use App\Services\SignalIntelligence\RiskDetectionService;
use App\Services\SignalIntelligence\SignalDashboardQueryService;
use App\Services\SignalIntelligence\SignalDetectionImpactAnalyzer;
use App\Services\SignalIntelligence\SignalDetectionPromotionService;
use App\Services\SignalIntelligence\SignalProcessingRunService;
use App\Services\SignalIntelligence\TrendDetectionService;
use App\Services\Onboarding\WorkspaceReadinessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

class SignalIntelligenceController extends Controller
{
    public function index(
        Request $request,
        SignalDashboardQueryService $dashboard,
        FeatureGate $featureGate,
        WorkspaceReadinessService $readiness,
        FirstValueActivationService $activation,
        FirstValueExperienceService $firstValue,
    ): View
    {
        $workspace = $this->resolveWorkspace($request);
        $featureGate->assert($workspace, 'signal_intelligence');

        $filters = $dashboard->filters($request, $workspace);
        $data = $dashboard->dashboard($workspace, $filters);
        $moduleReadiness = $readiness->getModuleReadiness($workspace, 'signal_intelligence');

        return view('app.signal-intelligence.index', array_merge($data, [
            'title' => 'Signal Intelligence',
            'workspace' => $workspace,
            'workspaces' => $this->availableWorkspaces($request),
            'filters' => $filters,
            'readiness' => $moduleReadiness,
            'emptyStateGuide' => $moduleReadiness ? $readiness->emptyStateFromResult($moduleReadiness) : [],
            'activation' => $activation->forWorkspace($workspace),
            'firstSignalCard' => $firstValue->firstSignalCard($workspace),
            'firstDetectionCard' => $firstValue->firstDetectionCard($workspace),
            'firstValueCelebrations' => $firstValue->celebrations($workspace),
        ]));
    }

    public function show(
        Request $request,
        SignalDetection $detection,
        FeatureGate $featureGate,
        FirstValueExperienceService $firstValue,
        SignalDetectionImpactAnalyzer $impactAnalyzer,
    ): View
    {
        $this->authorize('view', $detection);
        $workspace = $this->resolveWorkspace($request, $detection->workspace_id);
        $this->assertDetectionWorkspace($detection, $workspace);
        $featureGate->assert($workspace, 'signal_intelligence');

        $detection->load([
            'workspace',
            'clientSite',
            'events' => fn ($query) => $query->with(['signalSource', 'signalMention', 'signalFeedItem'])->orderByDesc('observed_at'),
        ]);

        return view('app.signal-intelligence.show', [
            'title' => 'Signal Intelligence',
            'workspace' => $workspace,
            'detection' => $detection,
            'impactAnalysis' => $impactAnalyzer->analyze(
                $detection,
                null,
                $detection->events->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            ),
            'firstDetectionCard' => $firstValue->detectionCard($detection),
            'firstValueCelebrations' => $firstValue->celebrations($workspace),
        ]);
    }

    public function run(
        Request $request,
        FeatureGate $featureGate,
        SignalProcessingRunService $runs,
        BrandMonitoringDetectionService $brandMonitoring,
        CompetitorMonitoringDetectionService $competitorMonitoring,
        LlmTrackingSignalAdapter $llmTrackingSignals,
        TrendDetectionService $trendDetection,
        RiskDetectionService $riskDetection
    ): RedirectResponse {
        $workspace = $this->resolveWorkspace($request);
        $featureGate->assert($workspace, 'signal_intelligence');
        $this->authorize('create', SignalDetection::class);

        $data = $request->validate([
            'category' => ['nullable', 'string', 'in:all,brand_monitoring,competitor_monitoring,trend_detection,risk_detection'],
            'site' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $from = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : now()->subDays(7)->startOfDay();
        $to = isset($data['date_to']) ? Carbon::parse($data['date_to'])->endOfDay() : now()->endOfDay();
        $category = (string) ($data['category'] ?? 'all');
        $site = $this->resolveSite($workspace, $data['site'] ?? null);

        $run = $runs->startRun($workspace, 'manual_detection', null, $site, [
            'category' => $category,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ]);

        try {
            $llmStats = $llmTrackingSignals->ingest($workspace);

            $eventsSeen = SignalEvent::query()
                ->where('workspace_id', $workspace->id)
                ->when($site, fn ($query) => $query->where('client_site_id', $site->id))
                ->whereBetween('observed_at', [$from, $to])
                ->count();

            if ($eventsSeen === 0) {
                $runs->markSucceeded($run, [
                    'category' => $category,
                    'items_seen' => 0,
                    'signals_created' => 0,
                    'detections_created' => 0,
                    'reason' => 'no_signal_events',
                    'llm_tracking_ingestion' => $llmStats,
                ]);

                return redirect()
                    ->route('app.signal-intelligence.index', array_filter([
                        'workspace' => $workspace->id,
                        'site' => $site?->id,
                    ]))
                    ->with('status', 'No signal events found for this period. Run an AI Visibility check first, or widen the date/site filter, then run detection again.');
            }

            $detections = collect();

            if ($category === 'all' || $category === SignalDetection::CATEGORY_BRAND_MONITORING) {
                $detections = $detections->merge($brandMonitoring->detect($workspace, $site, $from, $to));
            }

            if ($category === 'all' || $category === SignalDetection::CATEGORY_COMPETITOR_MONITORING) {
                $detections = $detections->merge($competitorMonitoring->detect($workspace, $site, $from, $to));
            }

            if ($category === 'all' || $category === SignalDetection::CATEGORY_TREND_DETECTION) {
                $detections = $detections->merge($trendDetection->detect($workspace, $site, $from, $to));
            }

            if ($category === 'all' || $category === SignalDetection::CATEGORY_RISK_DETECTION) {
                $detections = $detections->merge($riskDetection->detect($workspace, $site, $from, $to));
            }

            $runs->markSucceeded($run, [
                'items_seen' => $eventsSeen,
                'detections_created' => $detections->unique('id')->count(),
                'category' => $category,
                'llm_tracking_ingestion' => $llmStats,
            ]);
        } catch (\Throwable $exception) {
            $runs->markFailed($run, $exception->getMessage(), ['category' => $category]);

            return back()->withErrors(['signal_intelligence' => 'Signal detection run failed: '.$exception->getMessage()]);
        }

        return redirect()
            ->route('app.signal-intelligence.index', $request->only(['workspace', 'site', 'date_from', 'date_to']))
            ->with('status', sprintf('Signal detection run completed. %d detections updated.', $detections->unique('id')->count()));
    }

    public function review(Request $request, SignalDetection $detection, FeatureGate $featureGate): RedirectResponse
    {
        return $this->transition($request, $detection, $featureGate, 'review');
    }

    public function dismiss(Request $request, SignalDetection $detection, FeatureGate $featureGate): RedirectResponse
    {
        return $this->transition($request, $detection, $featureGate, 'dismiss');
    }

    public function resolve(Request $request, SignalDetection $detection, FeatureGate $featureGate): RedirectResponse
    {
        return $this->transition($request, $detection, $featureGate, 'resolve');
    }

    public function promote(
        Request $request,
        SignalDetection $detection,
        FeatureGate $featureGate,
        SignalDetectionPromotionService $promotionService
    ): RedirectResponse {
        $this->authorize('update', $detection);
        $workspace = $this->resolveWorkspace($request, $detection->workspace_id);
        $this->assertDetectionWorkspace($detection, $workspace);
        $featureGate->assert($workspace, 'signal_intelligence');

        try {
            $signal = $promotionService->promote($detection, $request->user());
        } catch (AuthorizationException $exception) {
            return back()->withErrors(['signal_intelligence' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['signal_intelligence' => 'Promotion failed. Please try again or review the detection evidence.']);
        }

        if (Route::has('app.agentic-marketing.intelligence.signals.show')) {
            return redirect()
                ->route('app.agentic-marketing.intelligence.signals.show', $signal)
                ->with('status', 'Detection promoted to Opportunity Intelligence.');
        }

        if (Route::has('app.agentic-marketing.intelligence.index')) {
            return redirect()
                ->route('app.agentic-marketing.intelligence.index', ['workspace_id' => $workspace->id])
                ->with('status', 'Detection promoted to Opportunity Intelligence.');
        }

        return redirect()
            ->route('app.signal-intelligence.detections.show', $detection)
            ->with('status', 'Detection promoted to Opportunity Intelligence.');
    }

    private function transition(Request $request, SignalDetection $detection, FeatureGate $featureGate, string $action): RedirectResponse
    {
        $this->authorize('update', $detection);
        $workspace = $this->resolveWorkspace($request, $detection->workspace_id);
        $this->assertDetectionWorkspace($detection, $workspace);
        $featureGate->assert($workspace, 'signal_intelligence');

        $target = match ($action) {
            'review' => SignalStatus::REVIEWING,
            'dismiss' => SignalStatus::DISMISSED,
            'resolve' => SignalStatus::RESOLVED,
            default => null,
        };

        if (! $target || ! $detection->canTransitionTo($target)) {
            return back()->withErrors(['signal_intelligence' => 'This detection cannot be marked as '.($target?->value ?? $action).' from its current status.']);
        }

        try {
            match ($action) {
                'review' => $detection->markReviewing(),
                'dismiss' => $detection->markDismissed(),
                'resolve' => $detection->markResolved(),
                default => throw new \InvalidArgumentException('Unsupported signal review action.'),
            };
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['signal_intelligence' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.signal-intelligence.detections.show', $detection)
            ->with('status', match ($action) {
                'review' => 'Detection marked as reviewing.',
                'dismiss' => 'Detection dismissed.',
                'resolve' => 'Detection resolved.',
                default => 'Detection updated.',
            });
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        $organizationId = $request->user()?->organization_id;

        $query = Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at');

        $workspaceId = $preferredWorkspaceId ?: $request->query('workspace');

        if ($workspaceId) {
            $workspace = (clone $query)->whereKey($workspaceId)->first();

            if (! $workspace) {
                throw new AuthorizationException('Workspace is not available for this user.');
            }

            return $workspace;
        }

        return $query->firstOrFail();
    }

    /**
     * @return Collection<int,Workspace>
     */
    private function availableWorkspaces(Request $request)
    {
        return Workspace::query()
            ->where('organization_id', $request->user()?->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'display_name']);
    }

    private function resolveSite(Workspace $workspace, mixed $siteId): ?ClientSite
    {
        if (! is_string($siteId) || trim($siteId) === '') {
            return null;
        }

        return ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($siteId)
            ->firstOrFail();
    }

    private function assertDetectionWorkspace(SignalDetection $detection, Workspace $workspace): void
    {
        if ((string) $detection->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Detection is not available for this workspace.');
        }
    }
}
