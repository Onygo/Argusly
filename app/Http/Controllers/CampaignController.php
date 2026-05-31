<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Campaign;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class CampaignController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        CampaignService $campaigns,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', Campaign::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Campaign::STATUSES)],
            'type' => ['nullable', 'string', Rule::in(Campaign::TYPES)],
        ]);

        return view('app.campaigns.index', [
            'account' => $account,
            'brand' => $brand,
            'campaigns' => $campaigns->paginatedForTenant($account, $brand, $filters),
            'stats' => $campaigns->dashboardStats($account, $brand),
            'filters' => $filters,
            'statuses' => Campaign::STATUSES,
            'types' => Campaign::TYPES,
            'assets' => $campaigns->availableAssets($account, $brand),
            'topics' => $campaigns->availableTopics($account, $brand),
            'signals' => $campaigns->availableSignals($account, $brand),
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        CampaignService $campaigns,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('create', Campaign::class);

        try {
            $campaign = $campaigns->create($account, $brand, $this->validatedAttributes($request));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['campaign' => $exception->getMessage()]);
        }

        return redirect()->route('app.campaigns.show', $campaign)->with('status', 'Campaign created.');
    }

    public function show(
        Campaign $campaign,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        CampaignService $campaigns,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('view', $campaign);

        $campaign = $campaigns->findForTenant($account, $brand, $campaign->id);

        return view('app.campaigns.show', [
            'campaign' => $campaign,
            'timeline' => $campaigns->timeline($campaign),
            'statuses' => Campaign::STATUSES,
            'types' => Campaign::TYPES,
            'assets' => $campaigns->availableAssets($account, $brand),
            'topics' => $campaigns->availableTopics($account, $brand),
            'signals' => $campaigns->availableSignals($account, $brand),
        ]);
    }

    public function update(
        Request $request,
        Campaign $campaign,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        CampaignService $campaigns,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('update', $campaign);

        $campaign = $campaigns->findForTenant($account, $brand, $campaign->id);

        try {
            $campaigns->update($campaign, $this->validatedAttributes($request));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['campaign' => $exception->getMessage()]);
        }

        return redirect()->route('app.campaigns.show', $campaign)->with('status', 'Campaign updated.');
    }

    public function destroy(
        Campaign $campaign,
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        CampaignService $campaigns,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('delete', $campaign);

        try {
            $campaigns->deleteForTenant($account, $brand, $campaign);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['campaign' => $exception->getMessage()]);
        }

        return redirect()->route('app.campaigns')->with('status', 'Campaign deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedAttributes(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'objective' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(Campaign::STATUSES)],
            'campaign_type' => ['required', 'string', Rule::in(Campaign::TYPES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'content_asset_ids' => ['nullable', 'array'],
            'content_asset_ids.*' => ['integer', 'exists:content_assets,id'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', 'exists:topics,id'],
            'signal_ids' => ['nullable', 'array'],
            'signal_ids.*' => ['integer', 'exists:intelligence_signals,id'],
        ]);
    }
}
