<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Briefing;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\BriefingService;
use App\Services\ContentLanguageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BriefingController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
        private readonly BriefingService $briefings,
        private readonly ContentLanguageService $languages,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Briefing::class);

        return view('app.briefings.index', [
            'account' => $account,
            'brand' => $brand,
            'briefings' => $this->briefings->paginatedForTenant($account, $brand),
            'campaigns' => $this->briefings->campaigns($account, $brand),
            'statuses' => Briefing::STATUSES,
            'channels' => BriefingService::CHANNELS,
            'languages' => $this->languages->enabledForBrand($brand),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', Briefing::class);

        $briefing = $this->briefings->create($account, $brand, $user, $request->validate([
            'scope' => ['required', 'string', Rule::in(['brand', 'account'])],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'title' => ['required', 'string', 'max:255'],
            'objective' => ['nullable', 'string'],
            'audience' => ['nullable', 'string'],
            'tone_of_voice' => ['nullable', 'string', 'max:255'],
            'key_message' => ['nullable', 'string'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', Rule::in(BriefingService::CHANNELS)],
            'languages' => ['nullable', 'array'],
            'languages.*' => $this->languages->validationRules($brand),
            'status' => ['required', 'string', Rule::in(Briefing::STATUSES)],
        ]));

        return redirect()->route('app.briefings.show', $briefing)->with('status', 'Briefing created.');
    }

    public function show(Request $request, Briefing $briefing): View
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('view', $briefing);

        $briefing = $this->briefings->findForTenant($account, $brand, $briefing->id);

        return view('app.briefings.show', [
            'briefing' => $briefing,
        ]);
    }

    public function requestApproval(Request $request, Briefing $briefing, ApprovalService $approvals): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        $briefing = $this->briefings->findForTenant($account, $brand, $briefing->id);
        Gate::authorize('update', $briefing);

        $briefing->forceFill(['status' => 'review'])->save();
        $approvals->request($briefing, $user, $request->input('notes'));

        return redirect()->route('app.briefings.show', $briefing)->with('status', 'Briefing sent for approval.');
    }

    public function approve(Request $request, Briefing $briefing, ApprovalService $approvals): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user);
        $brand = $this->currentBrand->get($user);

        abort_unless($account, 403);
        $briefing = $this->briefings->findForTenant($account, $brand, $briefing->id);
        Gate::authorize('update', $briefing);

        $approval = \App\Models\Approval::query()
            ->where('account_id', $briefing->account_id)
            ->where('subject_type', $briefing->getMorphClass())
            ->where('subject_id', $briefing->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $approvals->approve($approval, $user, $request->input('notes'));

        return redirect()->route('app.briefings.show', $briefing)->with('status', 'Briefing approved.');
    }
}
