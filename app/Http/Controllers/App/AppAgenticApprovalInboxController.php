<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AppAgenticApprovalInboxController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AgenticActionRun::class);

        $organizationId = (int) $request->user()->organization_id;
        $filters = [
            'action_type' => trim((string) $request->query('action_type', '')),
            'workspace_id' => trim((string) $request->query('workspace_id', '')),
        ];

        $runs = AgenticActionRun::query()
            ->with(['workspace', 'goal.clientSite', 'opportunity', 'content', 'action.opportunity', 'action.objective.clientSite'])
            ->where('status', AgenticActionRun::STATUS_APPROVAL_REQUIRED)
            ->whereHas('workspace', fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->when($filters['action_type'] !== '', fn (Builder $query) => $query->where('action_type', $filters['action_type']))
            ->when($filters['workspace_id'] !== '', fn (Builder $query) => $query->where('workspace_id', $filters['workspace_id']))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $workspaces = \App\Models\Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('display_name')
            ->get(['id', 'name', 'display_name']);

        return view('app.agentic-marketing.approvals.index', [
            'runs' => $runs,
            'filters' => $filters,
            'workspaces' => $workspaces,
            'actionTypes' => AgenticActionRun::query()
                ->whereHas('workspace', fn (Builder $query) => $query->where('organization_id', $organizationId))
                ->where('status', AgenticActionRun::STATUS_APPROVAL_REQUIRED)
                ->select('action_type')
                ->distinct()
                ->orderBy('action_type')
                ->pluck('action_type')
                ->all(),
        ]);
    }

    public function approve(Request $request, AgenticActionRun $run, AgenticActionRunLogger $logger): RedirectResponse
    {
        $this->authorize('approve', $run);

        if ($run->status !== AgenticActionRun::STATUS_APPROVAL_REQUIRED) {
            return back()->with('status', 'Only actions waiting for approval can be approved.');
        }

        DB::transaction(function () use ($request, $run, $logger): void {
            $run->forceFill([
                'status' => AgenticActionRun::STATUS_APPROVED,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'reason' => $run->reason ?: 'Customer approved this Agentic Marketing action.',
            ])->save();

            if ($run->action) {
                $run->action->forceFill([
                    'status' => AgenticMarketingAction::STATUS_APPROVED,
                    'approved_at' => now(),
                    'dismissed_at' => null,
                ])->save();
                $logger->markApproved($run->action->fresh(['objective', 'opportunity']), $request->user());
            }
        });

        return back()->with('status', 'Action approved.');
    }

    public function reject(Request $request, AgenticActionRun $run): RedirectResponse
    {
        $this->authorize('reject', $run);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $run, $data): void {
            $run->forceFill([
                'status' => AgenticActionRun::STATUS_REJECTED,
                'reason' => trim((string) ($data['note'] ?? '')) ?: 'Customer rejected this Agentic Marketing action.',
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            if ($run->action) {
                $run->action->forceFill([
                    'status' => AgenticMarketingAction::STATUS_DISMISSED,
                    'dismissed_at' => now(),
                    'error_message' => null,
                    'result' => array_merge((array) ($run->action->result ?? []), [
                        'approval' => [
                            'status' => 'rejected',
                            'reviewed_by' => $request->user()->id,
                            'reviewed_at' => now()->toIso8601String(),
                            'note' => trim((string) ($data['note'] ?? '')) ?: null,
                        ],
                    ]),
                ])->save();
            }
        });

        return back()->with('status', 'Action rejected. It cannot run unless it is resubmitted.');
    }

    public function requestChanges(Request $request, AgenticActionRun $run): RedirectResponse
    {
        $this->authorize('requestChanges', $run);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $notes = (array) data_get($run->input_snapshot, 'approval_notes', []);
        $notes[] = [
            'type' => 'changes_requested',
            'note' => trim((string) $data['note']),
            'user_id' => $request->user()->id,
            'created_at' => now()->toIso8601String(),
        ];

        $snapshot = (array) ($run->input_snapshot ?? []);
        data_set($snapshot, 'approval_notes', $notes);

        $run->forceFill([
            'status' => AgenticActionRun::STATUS_APPROVAL_REQUIRED,
            'reason' => 'Changes requested: '.Str::limit(trim((string) $data['note']), 220),
            'input_snapshot' => $snapshot,
        ])->save();

        return back()->with('status', 'Changes requested and recorded on the approval run.');
    }

    public function run(Request $request, AgenticActionRun $run, AgenticApprovalGate $gate, AgenticActionRunLogger $logger): RedirectResponse
    {
        $this->authorize('run', $run);

        if ($run->status !== AgenticActionRun::STATUS_APPROVED || ! $run->action) {
            return back()->with('status', 'Only approved actions with a linked action can be run.');
        }

        $decision = $gate->forMarketingAction($run->action, ['has_customer_approval' => true]);
        if (! (bool) $decision['allowed']) {
            $logger->recordGateDecision($run->action, $decision, $request->user(), [
                'source' => 'app.agentic-marketing.approvals.run',
            ]);

            return back()->with('status', (string) $decision['reason']);
        }

        $claimId = $this->claimAction($run->action);
        if (! $claimId) {
            return back()->with('status', 'This action is no longer ready to run.');
        }

        $logger->markQueued($run->action->fresh(['objective', 'opportunity']), $decision, $request->user(), $claimId);
        ExecuteAgenticMarketingActionJob::dispatch((string) $run->action_id, $request->user()->id, $claimId)
            ->onQueue('agentic-marketing');

        return back()->with('status', 'Approved action queued for execution.');
    }

    public function bulkApprove(Request $request): RedirectResponse
    {
        $this->authorize('bulkApprove', AgenticActionRun::class);

        $data = $request->validate([
            'run_ids' => ['required', 'array', 'max:50'],
            'run_ids.*' => ['uuid'],
        ]);

        $runs = AgenticActionRun::query()
            ->with(['workspace', 'action'])
            ->whereIn('id', $data['run_ids'])
            ->where('status', AgenticActionRun::STATUS_APPROVAL_REQUIRED)
            ->whereHas('workspace', fn (Builder $query) => $query->where('organization_id', $request->user()->organization_id))
            ->get()
            ->filter(fn (AgenticActionRun $run): bool => $this->isLowRisk($run));

        foreach ($runs as $run) {
            $this->authorize('approve', $run);
            $run->forceFill([
                'status' => AgenticActionRun::STATUS_APPROVED,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ])->save();

            if ($run->action) {
                $run->action->forceFill([
                    'status' => AgenticMarketingAction::STATUS_APPROVED,
                    'approved_at' => now(),
                    'dismissed_at' => null,
                ])->save();
            }
        }

        return back()->with('status', sprintf('Bulk approved %d low-risk action(s).', $runs->count()));
    }

    private function claimAction(AgenticMarketingAction $action): ?string
    {
        return DB::transaction(function () use ($action): ?string {
            $locked = AgenticMarketingAction::query()
                ->lockForUpdate()
                ->findOrFail($action->id);

            if ($locked->status !== AgenticMarketingAction::STATUS_APPROVED) {
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

            return $claimId;
        });
    }

    private function isLowRisk(AgenticActionRun $run): bool
    {
        $risk = (string) data_get($run->input_snapshot, 'payload.planning.risk_level', data_get($run->policy_snapshot, 'risk_level', 'low'));

        return in_array($risk, ['low', ''], true)
            && (int) ($run->estimated_credits ?? 0) <= 10;
    }
}
