<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EarlyAccessSignupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEarlyAccessPilotCostRequest;
use App\Http\Requests\Admin\StorePilotInvitationRequest;
use App\Http\Requests\Admin\UpdateEarlyAccessInternalNotesRequest;
use App\Models\AuditLog;
use App\Models\EarlyAccessPilotCost;
use App\Models\EarlyAccessSignup;
use App\Models\LlmRequest;
use App\Models\User;
use App\Services\EarlyAccessInvitationService;
use App\Services\EarlyAccessSignupService;
use App\Services\PilotQualificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminEarlyAccessController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => trim((string) $request->query('status', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
        ];

        if (! in_array($filters['status'], EarlyAccessSignupStatus::values(), true)) {
            $filters['status'] = '';
        }

        $query = EarlyAccessSignup::query()
            ->with(['activatedUser', 'assignedAdmin', 'workspace', 'latestInvite']);

        if ($filters['q'] !== '') {
            $query->where(function ($builder) use ($filters): void {
                $builder
                    ->where('full_name', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('email', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('company_name', 'like', '%' . $filters['q'] . '%');
            });
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('submitted_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('submitted_at', '<=', $filters['date_to']);
        }

        $signups = $query
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $this->attachPilotCostSummaries($signups->getCollection());

        $metrics = EarlyAccessSignup::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $costMetrics = $this->pilotCostMetrics();

        return view('admin.early-access.index', [
            'signups' => $signups,
            'filters' => $filters,
            'statuses' => EarlyAccessSignupStatus::cases(),
            'metrics' => $metrics,
            'costMetrics' => $costMetrics,
            'qualification' => app(PilotQualificationService::class),
        ]);
    }

    public function show(EarlyAccessSignup $signup): View
    {
        $signup->load([
            'invites.inviter',
            'assignedAdmin',
            'activatedUser.organization',
            'workspace.organization',
            'latestInvite',
            'pilotCosts.creator',
        ]);

        $existingUser = User::query()
            ->with('organization')
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $signup->email)])
            ->first();

        $activity = AuditLog::query()
            ->where('subject_type', EarlyAccessSignup::class)
            ->where('subject_id', (string) $signup->id)
            ->latest('created_at')
            ->limit(20)
            ->get();

        $duplicates = EarlyAccessSignup::query()
            ->where('email', $signup->email)
            ->where('id', '!=', $signup->id)
            ->latest('submitted_at')
            ->limit(10)
            ->get();

        $pilotCostSummary = $this->pilotCostSummary($signup);

        return view('admin.early-access.show', [
            'signup' => $signup,
            'existingUser' => $existingUser,
            'activity' => $activity,
            'duplicates' => $duplicates,
            'pilotCostSummary' => $pilotCostSummary,
            'pilotCostCategories' => EarlyAccessPilotCost::categoryOptions(),
            'qualification' => app(PilotQualificationService::class),
        ]);
    }

    public function invitePilotUser(
        StorePilotInvitationRequest $request,
        EarlyAccessSignupService $signups,
        EarlyAccessInvitationService $invites
    ): RedirectResponse {
        $signup = $signups->createManualPilotApplication($request->validated(), $request->user());

        return $this->handleInviteAction(
            fn () => $invites->send($signup, $request->user(), $request),
            'Pilot invitation sent.'
        );
    }

    public function markReviewed(
        Request $request,
        EarlyAccessSignup $signup,
        EarlyAccessSignupService $service
    ): RedirectResponse {
        $service->markReviewed($signup, $request->user(), $request);

        return back()->with('status', 'Signup marked as reviewed.');
    }

    public function approve(
        Request $request,
        EarlyAccessSignup $signup,
        EarlyAccessSignupService $service
    ): RedirectResponse {
        $service->approve($signup, $request->user(), $request);

        return back()->with('status', 'Signup approved.');
    }

    public function sendInvite(
        Request $request,
        EarlyAccessSignup $signup,
        EarlyAccessInvitationService $service
    ): RedirectResponse {
        return $this->handleInviteAction(
            fn () => $service->send($signup, $request->user(), $request),
            'Invite email sent.'
        );
    }

    public function resendInvite(
        Request $request,
        EarlyAccessSignup $signup,
        EarlyAccessInvitationService $service
    ): RedirectResponse {
        return $this->handleInviteAction(
            fn () => $service->resend($signup, $request->user(), $request),
            'Invite email resent.'
        );
    }

    public function reject(
        Request $request,
        EarlyAccessSignup $signup,
        EarlyAccessSignupService $service
    ): RedirectResponse {
        $service->reject($signup, $request->user(), $request);

        return back()->with('status', 'Signup rejected.');
    }

    public function updateNotes(
        UpdateEarlyAccessInternalNotesRequest $request,
        EarlyAccessSignup $signup,
        EarlyAccessSignupService $service
    ): RedirectResponse {
        $service->updateInternalNotes(
            $signup,
            $request->validated('internal_notes'),
            $request->user(),
            $request
        );

        return back()->with('status', 'Internal notes updated.');
    }

    public function storePilotCost(StoreEarlyAccessPilotCostRequest $request, EarlyAccessSignup $signup): RedirectResponse
    {
        $validated = $request->validated();

        $signup->pilotCosts()->create([
            'category' => $validated['category'],
            'description' => $validated['description'],
            'amount_cents' => (int) round(((float) $validated['amount_eur']) * 100),
            'currency' => 'EUR',
            'incurred_on' => $validated['incurred_on'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Pilot cost added.');
    }

    public function destroyPilotCost(EarlyAccessSignup $signup, EarlyAccessPilotCost $cost): RedirectResponse
    {
        if ((int) $cost->early_access_signup_id !== (int) $signup->id) {
            abort(404);
        }

        $cost->delete();

        return back()->with('status', 'Pilot cost removed.');
    }

    private function handleInviteAction(callable $callback, string $successMessage): RedirectResponse
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return back()->withErrors([
                'early_access' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Unable to send the invite right now.',
            ]);
        }

        return back()->with('status', $successMessage);
    }

    private function pilotCostSummary(EarlyAccessSignup $signup): array
    {
        $llmQuery = LlmRequest::query()
            ->when($signup->workspace_id, fn ($query) => $query->where('workspace_id', $signup->workspace_id));

        if (! $signup->workspace_id) {
            $llmQuery->whereRaw('1 = 0');
        }

        $manualCents = (int) $signup->pilotCosts->sum('amount_cents');
        $llmCostEur = (float) (clone $llmQuery)->sum('total_cost_eur');

        return [
            'llm_cost_eur' => $llmCostEur,
            'llm_requests' => (int) (clone $llmQuery)->count(),
            'llm_credits' => (float) (clone $llmQuery)->sum('credits_consumed'),
            'manual_cost_eur' => $manualCents / 100,
            'total_cost_eur' => $llmCostEur + ($manualCents / 100),
        ];
    }

    private function attachPilotCostSummaries($signups): void
    {
        $signupIds = $signups->pluck('id')->all();
        $workspaceIds = $signups->pluck('workspace_id')->filter()->values()->all();

        $manualCosts = EarlyAccessPilotCost::query()
            ->when($signupIds !== [], fn ($query) => $query->whereIn('early_access_signup_id', $signupIds))
            ->selectRaw('early_access_signup_id, SUM(amount_cents) as manual_cents')
            ->groupBy('early_access_signup_id')
            ->pluck('manual_cents', 'early_access_signup_id');

        $llmCosts = LlmRequest::query()
            ->when($workspaceIds !== [], fn ($query) => $query->whereIn('workspace_id', $workspaceIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->selectRaw('workspace_id, SUM(total_cost_eur) as llm_cost_eur, SUM(credits_consumed) as credits_consumed')
            ->groupBy('workspace_id')
            ->get()
            ->keyBy('workspace_id');

        $signups->each(function (EarlyAccessSignup $signup) use ($manualCosts, $llmCosts): void {
            $manualEur = ((int) ($manualCosts[$signup->id] ?? 0)) / 100;
            $llmRow = $signup->workspace_id ? $llmCosts->get((string) $signup->workspace_id) : null;
            $llmEur = (float) ($llmRow->llm_cost_eur ?? 0);
            $qualification = app(PilotQualificationService::class);
            $score = $signup->qualification_score ?? $qualification->score($signup);

            $signup->pilot_cost_summary = [
                'manual_cost_eur' => $manualEur,
                'llm_cost_eur' => $llmEur,
                'credits_consumed' => (float) ($llmRow->credits_consumed ?? 0),
                'total_cost_eur' => $manualEur + $llmEur,
            ];
            $signup->pilot_qualification = [
                'score' => $score,
                'label' => $qualification->label($score),
            ];
        });
    }

    private function pilotCostMetrics(): array
    {
        $workspaceIds = EarlyAccessSignup::query()
            ->whereNotNull('workspace_id')
            ->pluck('workspace_id')
            ->all();

        $manualCostEur = ((int) EarlyAccessPilotCost::query()->sum('amount_cents')) / 100;
        $llmCostEur = (float) LlmRequest::query()
            ->when($workspaceIds !== [], fn ($query) => $query->whereIn('workspace_id', $workspaceIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->sum('total_cost_eur');

        return [
            'manual_cost_eur' => $manualCostEur,
            'llm_cost_eur' => $llmCostEur,
            'total_cost_eur' => $manualCostEur + $llmCostEur,
        ];
    }
}
