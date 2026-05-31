<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\MarketingObjective;
use App\Models\MarketingTask;
use App\Models\MarketingWorkspace;
use App\Models\User;
use App\Services\MarketingOsService;
use App\Services\MarketingTaskService;
use App\Services\Subscriptions\ModuleAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MarketingOsController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        MarketingOsService $marketingOs,
        MarketingTaskService $tasks,
        ModuleAccessService $modules,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', MarketingWorkspace::class);
        Gate::authorize('viewAny', MarketingObjective::class);
        Gate::authorize('viewAny', MarketingTask::class);

        $filters = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
        ]);
        $account = $this->filteredAccount($user, $account, $filters['account_id'] ?? null);
        abort_unless($modules->accountHasAnyModule($account, ['campaigns', 'marketing_os']), 403);

        $brand = $this->filteredBrand($user, $account, $brand, $filters['brand_id'] ?? null);
        $campaign = $this->filteredCampaign($account, $brand, $filters['campaign_id'] ?? null);

        return view('app.marketing.index', [
            'account' => $account,
            'brand' => $brand,
            'campaign' => $campaign,
            'filters' => [
                'account_id' => $account->id,
                'brand_id' => $brand?->id,
                'campaign_id' => $campaign?->id,
            ],
            'accounts' => $this->accessibleAccounts($user),
            'brands' => $this->accessibleBrands($user, $account),
            'dashboard' => $marketingOs->dashboard($account, $brand, $campaign),
            'stats' => $marketingOs->stats($account, $brand),
            'taskStats' => $tasks->stats($account, $brand),
            'campaigns' => $marketingOs->availableCampaigns($account, $brand),
            'workspaceStatuses' => MarketingWorkspace::STATUSES,
            'objectiveStatuses' => MarketingObjective::STATUSES,
            'objectiveTypes' => MarketingObjective::TYPES,
            'taskStatuses' => MarketingTask::STATUSES,
            'taskPriorities' => MarketingTask::PRIORITIES,
            'taskRelatedTypes' => array_keys($tasks->relatedTypes()),
        ]);
    }

    public function storeWorkspace(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        MarketingOsService $marketingOs,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', MarketingWorkspace::class);

        $marketingOs->createWorkspace($account, $brand, $request->validate([
            'scope' => ['required', 'string', Rule::in(['brand', 'account'])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(MarketingWorkspace::STATUSES)],
        ]));

        return redirect()->route('app.marketing')->with('status', 'Marketing workspace created.');
    }

    public function storeObjective(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        MarketingOsService $marketingOs,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', MarketingObjective::class);

        $marketingOs->createObjective($account, $brand, $request->validate([
            'scope' => ['required', 'string', Rule::in(['brand', 'account'])],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', Rule::in(MarketingObjective::TYPES)],
            'status' => ['required', 'string', Rule::in(MarketingObjective::STATUSES)],
            'target_value' => ['nullable', 'numeric'],
            'current_value' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]));

        return redirect()->route('app.marketing')->with('status', 'Marketing objective created.');
    }

    private function filteredAccount(User $user, Account $currentAccount, mixed $accountId): Account
    {
        if ($accountId === null || $accountId === '') {
            return $currentAccount;
        }

        $account = Account::query()->findOrFail((int) $accountId);

        abort_unless($user->accounts()->whereKey($account->id)->wherePivot('status', 'active')->exists(), 403);

        return $account;
    }

    private function filteredBrand(User $user, Account $account, ?Brand $currentBrand, mixed $brandId): ?Brand
    {
        if ((string) $brandId === '0') {
            return null;
        }

        if ($brandId === null || $brandId === '') {
            return $currentBrand?->account_id === $account->id ? $currentBrand : null;
        }

        $brand = Brand::query()->where('account_id', $account->id)->findOrFail((int) $brandId);

        abort_unless($user->brands()->whereKey($brand->id)->wherePivot('account_id', $account->id)->wherePivot('status', 'active')->exists(), 403);

        return $brand;
    }

    private function filteredCampaign(Account $account, ?Brand $brand, mixed $campaignId): ?Campaign
    {
        if ($campaignId === null || $campaignId === '') {
            return null;
        }

        return Campaign::query()
            ->where('account_id', $account->id)
            ->when($brand !== null, fn ($query) => $query->where('brand_id', $brand->id))
            ->findOrFail((int) $campaignId);
    }

    private function accessibleAccounts(User $user)
    {
        return $user->accounts()->wherePivot('status', 'active')->orderBy('name')->get();
    }

    private function accessibleBrands(User $user, Account $account)
    {
        return $user->brands()
            ->wherePivot('account_id', $account->id)
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get();
    }
}
