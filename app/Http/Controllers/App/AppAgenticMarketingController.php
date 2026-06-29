<?php

namespace App\Http\Controllers\App;

use App\Enums\SupportedLanguage;
use App\Enums\AgenticMarketingApprovalMode;
use App\Http\Controllers\Controller;
use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\AgenticMarketingObjective;
use App\Models\ClientSite;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use App\Services\AgenticMarketing\AgenticMarketingAuditLogger;
use App\Services\AgenticMarketing\AgenticMarketingOpportunityDetectionService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppAgenticMarketingController extends Controller
{
    private const OBJECTIVE_STATUSES = ['active', 'paused', 'archived'];
    private const KPI_TYPES = ['ai_visibility', 'organic_traffic', 'conversions', 'content_velocity', 'pipeline_influence'];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AgenticMarketingObjective::class);

        $organizationId = $request->user()?->organization_id;
        $executionWorkspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->with(['clientSites' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->orderBy('created_at')
            ->get(['id', 'organization_id', 'name', 'display_name']);
        $executionWorkspace = $request->query('execution_workspace_id')
            ? $executionWorkspaces->firstWhere('id', (string) $request->query('execution_workspace_id'))
            : $executionWorkspaces->first();
        $executionSettings = $executionWorkspace ? $this->agenticExecutionSettingsFor($executionWorkspace) : null;
        $lastAutonomousAction = $executionWorkspace
            ? AgenticActionRun::query()
                ->forWorkspace($executionWorkspace)
                ->where('executed_by_agent', true)
                ->latest('updated_at')
                ->first()
            : null;

        $objectives = AgenticMarketingObjective::query()
            ->where('organization_id', $organizationId)
            ->with(['workspace', 'clientSite'])
            ->withCount(['actions', 'opportunities', 'runs'])
            ->latest()
            ->limit(20)
            ->get();

        $actionFilters = [
            'status' => trim((string) $request->query('status', '')),
            'type' => trim((string) $request->query('type', '')),
            'risk' => trim((string) $request->query('risk', '')),
            'approval_mode' => trim((string) $request->query('approval_mode', '')),
            'objective' => trim((string) $request->query('objective', '')),
        ];
        $runFilters = [
            'run_status' => trim((string) $request->query('run_status', '')),
            'run_action_type' => trim((string) $request->query('run_action_type', '')),
            'run_execution_mode' => trim((string) $request->query('run_execution_mode', '')),
        ];

        $baseActionQuery = AgenticMarketingAction::query()
            ->with(['objective.clientSite', 'opportunity', 'content', 'draft'])
            ->whereHas('objective', fn ($query) => $query->where('organization_id', $organizationId));

        $summaryActions = (clone $baseActionQuery)->get();
        $actions = (clone $baseActionQuery)
            ->when($actionFilters['status'] !== '', fn ($query) => $query->where('status', $actionFilters['status']))
            ->when($actionFilters['type'] !== '', fn ($query) => $query->where('action_type', $actionFilters['type']))
            ->when($actionFilters['risk'] !== '', fn ($query) => $query->where('payload->planning->risk_level', $actionFilters['risk']))
            ->when($actionFilters['approval_mode'] !== '', fn ($query) => $query->whereHas('objective', fn ($objectiveQuery) => $objectiveQuery->where('approval_mode', $actionFilters['approval_mode'])))
            ->when($actionFilters['objective'] !== '', fn ($query) => $query->where('objective_id', $actionFilters['objective']))
            ->latest()
            ->paginate(25)
            ->withQueryString();
        $gateService = app(AgenticApprovalGate::class);
        $actionGateDecisions = $actions->getCollection()
            ->mapWithKeys(fn (AgenticMarketingAction $action): array => [
                (string) $action->id => $gateService->forMarketingAction($action, [
                    'has_customer_approval' => $action->canExecute() || $action->status === AgenticMarketingAction::STATUS_RUNNING,
                ]),
            ]);
        $nextEligibleAutonomousActions = $executionWorkspace && $executionSettings?->isAutonomous()
            ? AgenticMarketingAction::query()
                ->with(['objective', 'opportunity'])
                ->whereHas('objective', fn ($query) => $query->where('workspace_id', $executionWorkspace->id))
                ->whereIn('status', [AgenticMarketingAction::STATUS_PROPOSED, AgenticMarketingAction::STATUS_APPROVED])
                ->latest()
                ->limit(20)
                ->get()
                ->mapWithKeys(fn (AgenticMarketingAction $action): array => [
                    (string) $action->id => [
                        'action' => $action,
                        'decision' => $gateService->forMarketingAction($action, ['has_customer_approval' => false]),
                    ],
                ])
                ->filter(fn (array $item): bool => (bool) data_get($item, 'decision.allowed'))
                ->take(5)
                ->values()
            : collect();
        $recentActionRuns = AgenticActionRun::query()
            ->with(['workspace', 'goal', 'action'])
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->when($runFilters['run_status'] !== '', fn ($query) => $query->where('status', $runFilters['run_status']))
            ->when($runFilters['run_action_type'] !== '', fn ($query) => $query->where('action_type', $runFilters['run_action_type']))
            ->when($runFilters['run_execution_mode'] !== '', fn ($query) => $query->where('execution_mode_snapshot', $runFilters['run_execution_mode']))
            ->latest()
            ->limit(20)
            ->get();

        $overview = [
            'objectives' => $objectives->count(),
            'open_opportunities' => $objectives->sum('opportunities_count'),
            'proposed_actions' => $summaryActions->where('status', AgenticMarketingAction::STATUS_PROPOSED)->count(),
            'approved_actions' => $summaryActions->where('status', AgenticMarketingAction::STATUS_APPROVED)->count(),
            'running_actions' => $summaryActions->where('status', AgenticMarketingAction::STATUS_RUNNING)->count(),
            'completed_actions' => $summaryActions->where('status', AgenticMarketingAction::STATUS_COMPLETED)->count(),
            'forecast_credits' => $summaryActions
                ->whereIn('status', [AgenticMarketingAction::STATUS_PROPOSED, AgenticMarketingAction::STATUS_APPROVED])
                ->sum(fn (AgenticMarketingAction $action): int => (int) ($action->estimated_credits ?? 0)),
            'high_risk_actions' => $summaryActions
                ->filter(fn (AgenticMarketingAction $action): bool => data_get($action->payload, 'planning.risk_level') === 'high')
                ->count(),
        ];
        $budgetSummaries = $objectives->mapWithKeys(fn (AgenticMarketingObjective $objective): array => [
            (string) $objective->id => $this->objectiveBudgetSummary($objective),
        ]);

        return view('app.agentic-marketing.index', [
            'objectives' => $objectives,
            'actions' => $actions,
            'overview' => $overview,
            'budgetSummaries' => $budgetSummaries,
            'actionFilters' => $actionFilters,
            'runFilters' => $runFilters,
            'recentActionRuns' => $recentActionRuns,
            'actionGateDecisions' => $actionGateDecisions,
            'executionWorkspaces' => $executionWorkspaces,
            'executionWorkspace' => $executionWorkspace,
            'executionSettings' => $executionSettings,
            'lastAutonomousAction' => $lastAutonomousAction,
            'nextEligibleAutonomousActions' => $nextEligibleAutonomousActions,
            'actionRunStatusOptions' => AgenticActionRun::statuses(),
            'actionStatusOptions' => ['proposed', 'approved', 'running', 'completed', 'failed', 'dismissed'],
            'actionTypeOptions' => ['refresh_article', 'add_answer_block', 'improve_internal_links', 'create_locale_variant', 'update_meta', 'add_schema', 'create_article'],
            'riskOptions' => ['low', 'medium', 'high'],
            'approvalModes' => AgenticMarketingApprovalMode::values(),
        ]);
    }

    private function agenticExecutionSettingsFor(Workspace $workspace): AgenticMarketingExecutionSetting
    {
        return AgenticMarketingExecutionSetting::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('brand_voice_id')
            ->first()
            ?: AgenticMarketingExecutionSetting::defaultsFor($workspace);
    }

    public function createObjective(Request $request): View
    {
        $this->authorize('create', AgenticMarketingObjective::class);

        return view('app.agentic-marketing.objectives.form', array_merge(
            $this->objectiveFormOptions($request),
            [
                'objective' => new AgenticMarketingObjective([
                    'status' => 'active',
                    'approval_mode' => 'manual',
                    'locale' => SupportedLanguage::default()->value,
                ]),
                'mode' => 'create',
            ]
        ));
    }

    public function storeObjective(Request $request): RedirectResponse
    {
        $this->authorize('create', AgenticMarketingObjective::class);

        $data = $this->validatedObjectiveData($request);

        $objective = AgenticMarketingObjective::query()->create(array_merge($data, [
            'organization_id' => $request->user()?->organization_id,
        ]));

        return redirect()
            ->route('app.agentic-marketing.objectives.show', $objective)
            ->with('status', 'Objective created. Run the first scan to turn this goal into concrete customer actions.');
    }

    public function showObjective(
        Request $request,
        AgenticMarketingObjective $objective,
        AgenticOpportunityCanonicalReadService $opportunityReadService,
    ): View {
        $this->authorize('view', $objective);

        $objective->load(['workspace', 'clientSite'])
            ->loadCount(['opportunities', 'actions', 'runs']);

        $opportunities = $objective->opportunities()
            ->orderByDesc('priority_score')
            ->latest()
            ->limit(100)
            ->get();
        $opportunityReadModels = $opportunityReadService->readMany($opportunities);
        $actions = $objective->actions()->with(['opportunity', 'content', 'draft'])->latest()->limit(50)->get();
        $runs = $objective->runs()->latest()->limit(25)->get();
        $runItems = \App\Models\AgenticMarketingRunItem::query()
            ->where('objective_id', $objective->id)
            ->with(['run', 'opportunity', 'action'])
            ->latest()
            ->limit(50)
            ->get();
        $auditLogs = \App\Models\AgenticMarketingAuditLog::query()
            ->where('objective_id', $objective->id)
            ->latest()
            ->limit(50)
            ->get();

        $health = [
            'average_priority' => (int) round((float) $opportunityReadModels->avg('priorityScore')),
            'open_opportunities' => $opportunityReadModels->where('status', 'open')->count(),
            'blocked_actions' => $actions->filter(fn (AgenticMarketingAction $action): bool => data_get($action->payload, 'planning.prerequisites.met') === false)->count(),
            'high_risk_actions' => $actions->filter(fn (AgenticMarketingAction $action): bool => data_get($action->payload, 'planning.risk_level') === 'high')->count(),
            'completed_actions' => $actions->where('status', AgenticMarketingAction::STATUS_COMPLETED)->count(),
            'forecast_credits' => $actions
                ->whereIn('status', [AgenticMarketingAction::STATUS_PROPOSED, AgenticMarketingAction::STATUS_APPROVED])
                ->sum(fn (AgenticMarketingAction $action): int => (int) ($action->estimated_credits ?? 0)),
        ];

        $opportunityMap = [
            'type' => $opportunities->groupBy(fn ($opportunity): string => (string) ($opportunity->type ?: 'unknown'))->map->count()->sortDesc(),
            'locale' => $opportunities->groupBy(fn ($opportunity): string => (string) (data_get($opportunity->payload, 'signals.source_locale') ?: data_get($opportunity->payload, 'locale') ?: $objective->locale ?: 'en'))->map->count()->sortDesc(),
            'campaign_content' => $opportunities->groupBy(fn ($opportunity): string => $opportunity->content_id ? 'Content-backed' : 'Campaign/topic')->map->count()->sortDesc(),
            'risk' => $opportunities->groupBy(function ($opportunity): string {
                $riskScore = (int) data_get($opportunity->payload, 'score_explanation.risk_score', 0);

                return match (true) {
                    $riskScore >= 60 => 'high',
                    $riskScore >= 35 => 'medium',
                    default => 'low',
                };
            })->map->count()->sortDesc(),
        ];

        return view('app.agentic-marketing.objectives.show', [
            'objective' => $objective,
            'opportunities' => $opportunities,
            'opportunityReadModels' => $opportunityReadModels,
            'actions' => $actions,
            'runs' => $runs,
            'runItems' => $runItems,
            'auditLogs' => $auditLogs,
            'health' => $health,
            'opportunityMap' => $opportunityMap,
            'budgetSummary' => $this->objectiveBudgetSummary($objective, $actions),
            'aiVisibilitySummary' => $this->objectiveAiVisibilitySummary($objective),
        ]);
    }

    public function showAction(Request $request, AgenticMarketingAction $action): View
    {
        $this->authorize('view', $action);

        $action->load(['objective', 'opportunity', 'content', 'draft', 'run.items']);

        $timeline = collect()
            ->merge(\App\Models\AgenticMarketingRunItem::query()
                ->where('action_id', $action->id)
                ->latest()
                ->get()
                ->map(fn ($item) => [
                    'kind' => 'Run step',
                    'status' => $this->agenticMarketingTimelineStatus((string) $item->status),
                    'label' => $this->agenticMarketingStepLabel((string) $item->name),
                    'time' => $item->created_at,
                    'message' => $item->error_message ?: $this->agenticMarketingTimelineStatus((string) $item->type),
                ]))
            ->merge(\App\Models\AgenticMarketingAuditLog::query()
                ->where('action_id', $action->id)
                ->latest()
                ->get()
                ->map(fn ($log) => [
                    'kind' => 'Audit event',
                    'status' => $this->agenticMarketingEventStatus((string) $log->event),
                    'label' => $this->agenticMarketingEventLabel((string) $log->event),
                    'time' => $log->created_at,
                    'message' => data_get($log->after, 'error_message') ?: data_get($log->metadata, 'message'),
                ]))
            ->sortByDesc('time')
            ->values();

        return view('app.agentic-marketing.actions.show', [
            'action' => $action,
            'timeline' => $timeline,
        ]);
    }

    private function agenticMarketingEventLabel(string $event): string
    {
        return [
            'objective.created' => 'Objective created',
            'objective.updated' => 'Objective updated',
            'opportunity.created' => 'Opportunity created',
            'opportunity.updated' => 'Opportunity updated',
            'action.created' => 'Action proposed',
            'action.updated' => 'Action updated',
            'action.executed' => 'Action completed',
            'action.execution_failed' => 'Execution failed',
            'run.started' => 'Run started',
            'run.completed' => 'Run completed',
            'run.failed' => 'Run failed',
        ][$event] ?? str($event)->replace(['.', '_'], ' ')->title()->toString();
    }

    private function agenticMarketingEventStatus(string $event): string
    {
        return match ($event) {
            'action.created', 'opportunity.created', 'objective.created' => 'Created',
            'action.updated', 'opportunity.updated', 'objective.updated' => 'Updated',
            'action.executed', 'run.completed' => 'Completed',
            'action.execution_failed', 'run.failed' => 'Failed',
            'run.started' => 'Started',
            default => 'Recorded',
        };
    }

    private function agenticMarketingStepLabel(string $name): string
    {
        return str($name)
            ->replace('OpportunityDetector', '')
            ->replace('AgenticMarketing', '')
            ->headline()
            ->toString();
    }

    private function agenticMarketingTimelineStatus(string $status): string
    {
        return str($status)->replace('_', ' ')->headline()->toString();
    }

    public function editObjective(Request $request, AgenticMarketingObjective $objective): View
    {
        $this->authorize('update', $objective);

        return view('app.agentic-marketing.objectives.form', array_merge(
            $this->objectiveFormOptions($request),
            [
                'objective' => $objective->load(['workspace', 'clientSite']),
                'mode' => 'edit',
            ]
        ));
    }

    public function updateObjective(Request $request, AgenticMarketingObjective $objective): RedirectResponse
    {
        $this->authorize('update', $objective);

        $objective->forceFill($this->validatedObjectiveData($request))->save();

        return redirect()
            ->route('app.agentic-marketing.objectives.show', $objective)
            ->with('status', 'Objective updated.');
    }

    public function destroyObjective(Request $request, AgenticMarketingObjective $objective): RedirectResponse
    {
        $this->authorize('delete', $objective);

        if ($objective->actions()->exists() || $objective->opportunities()->exists() || $objective->runs()->exists()) {
            return back()->with('status', 'Objectives with opportunities, actions, or runs cannot be deleted. Archive the objective instead.');
        }

        $objective->delete();

        return redirect()
            ->route('app.agentic-marketing.index')
            ->with('status', 'Objective deleted.');
    }

    public function scanObjective(
        Request $request,
        AgenticMarketingObjective $objective,
        AgenticMarketingOpportunityDetectionService $detection,
        AgenticMarketingActionPlanner $planner
    ): RedirectResponse {
        $this->authorize('update', $objective);

        if ((string) $objective->status !== 'active') {
            return back()->with('status', 'Only active objectives can be scanned. Activate this objective first.');
        }

        if (! $objective->workspace_id) {
            return back()->with('status', 'Select a workspace before scanning this objective.');
        }

        $detectionResult = $detection->detect((string) $objective->id);
        $planningResult = $planner->planForObjective($objective->fresh(['workspace', 'clientSite']));

        $createdOpportunities = (int) ($detectionResult['created'] ?? 0);
        $reusedOpportunities = (int) ($detectionResult['reused'] ?? 0);
        $createdActions = (int) ($planningResult['created'] ?? 0);
        $reusedActions = (int) ($planningResult['reused'] ?? 0);
        $skippedActions = (int) ($planningResult['skipped'] ?? 0);

        if ((int) ($detectionResult['failed'] ?? 0) > 0) {
            return back()->with('status', 'The scan hit an error while reading objective signals. Review recent run items for details.');
        }

        if ($createdActions + $reusedActions > 0) {
            return redirect()
                ->route('app.agentic-marketing.objectives.show', $objective)
                ->with('status', sprintf(
                    'Scan complete: %d new opportunities, %d reused, %d actions ready for review%s.',
                    $createdOpportunities,
                    $reusedOpportunities,
                    $createdActions + $reusedActions,
                    $skippedActions > 0 ? sprintf(', %d skipped because prerequisites were missing', $skippedActions) : ''
                ));
        }

        return redirect()
            ->route('app.agentic-marketing.objectives.show', $objective)
            ->with('status', sprintf(
                'Scan complete: %d new opportunities, %d reused, but no executable actions yet. Add content, lifecycle, AI visibility, or SEO signals and scan again.',
                $createdOpportunities,
                $reusedOpportunities
            ));
    }

    public function approve(Request $request, AgenticMarketingAction $action): RedirectResponse
    {
        $this->authorize('approve', $action);

        if ($action->status !== AgenticMarketingAction::STATUS_PROPOSED) {
            return back()->with('status', 'Only proposed actions can be approved.');
        }

        $action->forceFill([
            'status' => AgenticMarketingAction::STATUS_APPROVED,
            'approved_at' => now(),
            'dismissed_at' => null,
        ])->save();

        Log::info('agentic_marketing.action.approved', $this->logContext($request, $action));
        app(AgenticMarketingAuditLogger::class)->record($action->loadMissing(['objective', 'opportunity', 'run']), 'action.approved', null, [
            'status' => $action->status,
            'approved_at' => optional($action->approved_at)->toIso8601String(),
        ]);
        app(AgenticActionRunLogger::class)->markApproved($action->loadMissing(['objective', 'opportunity']), $request->user());

        return back()->with('status', 'Action approved. It is ready for supervised execution.');
    }

    public function dismiss(Request $request, AgenticMarketingAction $action): RedirectResponse
    {
        $this->authorize('dismiss', $action);

        if (! in_array($action->status, [AgenticMarketingAction::STATUS_PROPOSED, AgenticMarketingAction::STATUS_APPROVED], true)) {
            return back()->with('status', 'Only proposed or approved actions can be dismissed.');
        }

        $action->forceFill([
            'status' => AgenticMarketingAction::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ])->save();

        Log::info('agentic_marketing.action.dismissed', $this->logContext($request, $action));
        app(AgenticMarketingAuditLogger::class)->record($action->loadMissing(['objective', 'opportunity', 'run']), 'action.dismissed', null, [
            'status' => $action->status,
            'dismissed_at' => optional($action->dismissed_at)->toIso8601String(),
        ]);

        return back()->with('status', 'Action dismissed.');
    }

    public function execute(Request $request, AgenticMarketingAction $action): RedirectResponse
    {
        $this->authorize('execute', $action);

        $gate = app(AgenticApprovalGate::class)->forMarketingAction($action, [
            'has_customer_approval' => $action->canExecute(),
        ]);

        if (! (bool) $gate['allowed']) {
            app(AgenticActionRunLogger::class)->recordGateDecision($action, $gate, $request->user(), [
                'source' => 'app.agentic-marketing.actions.execute',
            ]);

            return back()->with('status', (string) $gate['reason']);
        }

        $claimId = $this->claimActionForExecution($request, $action, gate: $gate);
        if (! $claimId) {
            return back()->with('status', 'Only approved actions can be executed. Running and completed actions cannot be executed again.');
        }

        ExecuteAgenticMarketingActionJob::dispatch((string) $action->id, $request->user()?->id, $claimId);

        return back()->with('status', 'Action execution queued. Argusly will create drafts or proposals only; nothing is published automatically.');
    }

    public function retry(Request $request, AgenticMarketingAction $action): RedirectResponse
    {
        $this->authorize('retry', $action);

        $gate = app(AgenticApprovalGate::class)->forMarketingAction($action, [
            'has_customer_approval' => $action->canRetry(),
            'is_retry' => true,
        ]);

        if (! (bool) $gate['allowed']) {
            app(AgenticActionRunLogger::class)->recordGateDecision($action, $gate, $request->user(), [
                'source' => 'app.agentic-marketing.actions.retry',
            ]);

            return back()->with('status', (string) $gate['reason']);
        }

        $claimId = $this->claimActionForExecution($request, $action, retryFailed: true, gate: $gate);
        if (! $claimId) {
            return back()->with('status', 'Only failed actions can be retried.');
        }

        ExecuteAgenticMarketingActionJob::dispatch((string) $action->id, $request->user()?->id, $claimId);

        return back()->with('status', 'Retry queued.');
    }

    private function claimActionForExecution(Request $request, AgenticMarketingAction $action, bool $retryFailed = false, array $gate = []): ?string
    {
        return DB::transaction(function () use ($request, $action, $retryFailed, $gate): ?string {
            $locked = AgenticMarketingAction::query()
                ->with('objective')
                ->lockForUpdate()
                ->findOrFail($action->id);

            $this->authorize($retryFailed ? 'retry' : 'execute', $locked);

            if ($retryFailed) {
                if (! $locked->canRetry()) {
                    return null;
                }
            } elseif (! $locked->canExecute()) {
                return null;
            }

            $claimId = (string) Str::uuid();

            $locked->forceFill([
                'status' => AgenticMarketingAction::STATUS_RUNNING,
                'execution_claim_id' => $claimId,
                'execution_claimed_at' => now(),
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            Log::info('agentic_marketing.action.claimed_for_execution', $this->logContext($request, $locked));
            app(AgenticMarketingAuditLogger::class)->record($locked->loadMissing(['objective', 'opportunity', 'run']), $retryFailed ? 'action.retry_queued' : 'action.execution_queued', null, [
                'status' => $locked->status,
                'execution_claim_id' => $claimId,
            ]);
            app(AgenticActionRunLogger::class)->markQueued($locked->loadMissing(['objective', 'opportunity']), $gate, $request->user(), $claimId);

            return $claimId;
        });
    }

    private function validatedObjectiveData(Request $request): array
    {
        $organizationId = (int) $request->user()?->organization_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'goal' => ['required', 'string', 'max:500'],
            'kpi_type' => ['required', 'string', Rule::in(self::KPI_TYPES)],
            'locale' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'workspace_id' => [
                'required',
                'string',
                Rule::exists('workspaces', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'client_site_id' => ['nullable', 'string'],
            'audience' => ['nullable', 'string', 'max:1000'],
            'competitors' => ['nullable', 'string', 'max:2000'],
            'approval_mode' => ['required', 'string', Rule::in(AgenticMarketingApprovalMode::values())],
            'monthly_credit_budget' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'status' => ['required', 'string', Rule::in(self::OBJECTIVE_STATUSES)],
        ]);

        if (! empty($data['client_site_id'])) {
            $siteBelongsToWorkspace = ClientSite::query()
                ->whereKey($data['client_site_id'])
                ->where('workspace_id', $data['workspace_id'])
                ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
                ->exists();

            if (! $siteBelongsToWorkspace) {
                throw ValidationException::withMessages([
                    'client_site_id' => 'Select a site that belongs to the selected workspace.',
                ]);
            }
        }

        $competitors = $this->parseTextareaList((string) ($data['competitors'] ?? ''));
        unset($data['competitors']);

        return array_merge($data, [
            'target_market' => $data['audience'] ?? null,
            'languages' => [$data['locale']],
            'competitors' => $competitors,
            'monthly_credit_budget' => $data['monthly_credit_budget'] ?? null,
        ]);
    }

    private function objectiveFormOptions(Request $request): array
    {
        $organizationId = (int) $request->user()?->organization_id;
        $workspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $sites = ClientSite::query()
            ->with('workspace:id,name,organization_id')
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->orderBy('name')
            ->get(['id', 'workspace_id', 'name', 'site_url']);

        return [
            'workspaces' => $workspaces,
            'sites' => $sites,
            'locales' => SupportedLanguage::options(),
            'kpiTypes' => self::KPI_TYPES,
            'approvalModes' => AgenticMarketingApprovalMode::values(),
            'statuses' => self::OBJECTIVE_STATUSES,
        ];
    }

    private function objectiveBudgetSummary(AgenticMarketingObjective $objective, $actions = null): array
    {
        $budget = $objective->monthly_credit_budget;
        $monthStart = now()->startOfMonth();
        $monthlyActions = $actions
            ? collect($actions)->filter(fn (AgenticMarketingAction $action): bool => $action->created_at?->greaterThanOrEqualTo($monthStart) ?? false)
            : $objective->actions()->where('created_at', '>=', $monthStart)->get();

        $captured = (int) $monthlyActions->sum(fn (AgenticMarketingAction $action): int => (int) ($action->credits_captured ?? 0));
        $reserved = (int) $monthlyActions
            ->whereIn('status', [AgenticMarketingAction::STATUS_APPROVED, AgenticMarketingAction::STATUS_RUNNING])
            ->sum(fn (AgenticMarketingAction $action): int => (int) ($action->credits_reserved ?? 0));
        $forecast = (int) $monthlyActions
            ->whereIn('status', [AgenticMarketingAction::STATUS_PROPOSED, AgenticMarketingAction::STATUS_APPROVED, AgenticMarketingAction::STATUS_RUNNING])
            ->sum(fn (AgenticMarketingAction $action): int => (int) ($action->credits_reserved ?? $action->estimated_credits ?? 0));
        $remaining = $budget !== null ? (int) $budget - $captured - $reserved : null;
        $forecastRemaining = $budget !== null ? (int) $budget - $captured - $forecast : null;
        $lowThreshold = $budget !== null ? max(10, (int) ceil((int) $budget * 0.2)) : null;

        return [
            'budget' => $budget !== null ? (int) $budget : null,
            'captured' => $captured,
            'reserved' => $reserved,
            'reserved_or_forecast' => $forecast,
            'remaining' => $remaining,
            'forecast_remaining' => $forecastRemaining,
            'is_low' => $budget !== null && $remaining !== null && $remaining >= 0 && $remaining <= $lowThreshold,
            'is_exceeded' => $budget !== null && $remaining !== null && $remaining < 0,
            'is_forecast_exceeded' => $budget !== null && $forecastRemaining !== null && $forecastRemaining < 0,
        ];
    }

    private function objectiveAiVisibilitySummary(AgenticMarketingObjective $objective): array
    {
        $locales = collect((array) ($objective->languages ?: [$objective->locale ?: 'en']))
            ->push($objective->locale ?: 'en')
            ->map(fn (mixed $locale): string => trim((string) $locale))
            ->filter()
            ->unique()
            ->values();

        $queries = \App\Models\LlmTrackingQuery::query()
            ->with('latestRun')
            ->where('workspace_id', $objective->workspace_id)
            ->when($objective->client_site_id, fn ($query) => $query->where('client_site_id', $objective->client_site_id))
            ->when($locales->isNotEmpty(), fn ($query) => $query->whereIn('locale', $locales->all()))
            ->where('is_active', true)
            ->limit(200)
            ->get();

        $runs = $queries->pluck('latestRun')->filter(fn ($run): bool => $run && $run->status === 'succeeded');
        $score = fn (mixed $value): float => is_numeric($value) ? ((float) $value > 1 ? (float) $value / 100 : (float) $value) : 0.0;
        $runCount = max(1, $runs->count());
        $avgVisibility = $runs
            ->map(fn ($run): float => $score($run->ai_visibility_score))
            ->filter(fn (float $value): bool => $value > 0)
            ->avg();

        return [
            'query_count' => $queries->count(),
            'tracked_run_count' => $runs->count(),
            'avg_ai_visibility_score' => $avgVisibility !== null ? (int) round($avgVisibility * 100) : null,
            'brand_presence_rate' => (int) round(($runs->filter(fn ($run): bool => (bool) $run->brand_mentioned)->count() / $runCount) * 100),
            'citation_rate' => (int) round(($runs->filter(fn ($run): bool => (bool) $run->urls_cited || $score($run->citation_score) > 0)->count() / $runCount) * 100),
            'competitor_dominance_count' => $runs->filter(function ($run) use ($score): bool {
                return (bool) $run->competitors_mentioned && ($score($run->competitor_share_score) < 0.45 || count((array) $run->competitor_hits) > count((array) $run->brand_hits));
            })->count(),
            'locale_gaps' => $queries
                ->groupBy(fn ($query): string => (string) ($query->locale ?: 'en'))
                ->map(function ($localeQueries) use ($score): ?int {
                    $average = $localeQueries
                        ->pluck('latestRun')
                        ->filter(fn ($run): bool => $run && $run->status === 'succeeded')
                        ->map(fn ($run): float => $score($run->ai_visibility_score))
                        ->filter(fn (float $value): bool => $value > 0)
                        ->avg();

                    return $average !== null && $average < 0.6 ? (int) round($average * 100) : null;
                })
                ->filter(fn (?int $value): bool => $value !== null)
                ->all(),
        ];
    }

    private function parseTextareaList(string $value): array
    {
        return collect(preg_split('/\r\n|\r|\n|,/', $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function logContext(Request $request, AgenticMarketingAction $action): array
    {
        return [
            'action_id' => (string) $action->id,
            'objective_id' => (string) $action->objective_id,
            'action_type' => (string) $action->action_type,
            'status' => (string) $action->status,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->user()?->organization_id,
        ];
    }
}
