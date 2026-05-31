<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\IntegrationConnection;
use App\Models\Module;
use App\Models\Property;
use App\Models\PublishingChannel;
use App\Models\SubscriptionModule;
use App\Models\User;
use App\Services\Integrations\LinkedIn\LinkedInProvider;
use App\Services\SocialProfiles\SocialProfileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private Account $account;

    private ?Brand $brand;

    private User $user;

    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
    ) {}

    public function account(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.account', [
            'account' => $this->account,
            'brand' => $this->brand,
        ]);
    }

    public function brands(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.brands', [
            'account' => $this->account,
            'brand' => $this->brand,
            'brands' => $this->account->brands()->orderBy('name')->get(),
        ]);
    }

    public function team(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.team', [
            'account' => $this->account,
            'brand' => $this->brand,
            'accountMembers' => $this->accountMembers(),
            'brandMembers' => $this->brandMembers(),
        ]);
    }

    public function modules(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.modules', [
            'account' => $this->account,
            'brand' => $this->brand,
            'modules' => $this->modulesForAccount(),
        ]);
    }

    public function integrations(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.integrations', [
            'account' => $this->account,
            'brand' => $this->brand,
            'connections' => $this->integrationConnections(),
            'linkedinProvider' => app(LinkedInProvider::class),
        ]);
    }

    public function linkedinIntegration(Request $request, LinkedInProvider $linkedin): View
    {
        $this->resolveContext($request);

        return view('app.settings.integrations.linkedin', [
            'account' => $this->account,
            'brand' => $this->brand,
            'provider' => $linkedin,
            'connections' => $this->linkedinIntegrationConnections(),
        ]);
    }

    public function socialProfiles(Request $request, SocialProfileService $socialProfiles): View
    {
        $this->resolveContext($request);

        return view('app.settings.social-profiles', [
            'account' => $this->account,
            'brand' => $this->brand,
            'profiles' => $socialProfiles->profilesFor($this->user, $this->account, $this->brand),
            'socialProfiles' => $socialProfiles,
        ]);
    }

    public function properties(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.properties', [
            'account' => $this->account,
            'brand' => $this->brand,
            'properties' => $this->propertyRecords(),
            'types' => Property::TYPES,
        ]);
    }

    public function channels(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.channels', [
            'account' => $this->account,
            'brand' => $this->brand,
            'channels' => $this->channelRecords(),
            'providers' => PublishingChannel::PROVIDERS,
        ]);
    }

    private function resolveContext(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();

        $this->user = $user;
        $this->account = $this->currentAccount->get($user) ?? abort(403);
        $this->brand = $this->currentBrand->get($user);
    }

    private function accountMembers(): Collection
    {
        return $this->account->memberships()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn ($membership) => [
                'user' => $membership->user,
                'status' => $membership->status,
                'role' => $this->roleForUser($membership->user, $this->account, null),
                'joined_at' => $membership->joined_at,
            ]);
    }

    private function brandMembers(): Collection
    {
        if (! $this->brand) {
            return collect();
        }

        return $this->brand->memberships()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn ($membership) => [
                'user' => $membership->user,
                'status' => $membership->status,
                'role' => $this->roleForUser($membership->user, $this->account, $this->brand),
                'joined_at' => $membership->joined_at,
            ]);
    }

    private function roleForUser(User $user, Account $account, ?Brand $brand): ?string
    {
        return $user->roleAssignments()
            ->where('account_id', $account->id)
            ->when($brand, fn (Builder $query) => $query->where('brand_id', $brand->id), fn (Builder $query) => $query->whereNull('brand_id'))
            ->whereHas('role')
            ->with('role')
            ->get()
            ->sortByDesc(fn ($assignment) => $assignment->role->priority)
            ->first()
            ?->role
            ?->display_name;
    }

    private function modulesForAccount(): Collection
    {
        $activeModules = SubscriptionModule::query()
            ->active()
            ->where('account_id', $this->account->id)
            ->whereHas('subscription', fn (Builder $query) => $query->active())
            ->pluck('module_id')
            ->all();

        return Module::query()
            ->orderBy('name')
            ->get()
            ->map(fn ($module) => [
                'module' => $module,
                'active' => in_array($module->id, $activeModules, true),
            ]);
    }

    private function integrationConnections(?string $provider = null): Collection
    {
        return IntegrationConnection::query()
            ->active()
            ->where('account_id', $this->account->id)
            ->when($provider !== null, fn (Builder $query) => $query->whereHas('integration', fn (Builder $integration) => $integration->where('key', $provider)))
            ->when(
                $this->brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $this->brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with(['integration', 'brand'])
            ->latest('created_at')
            ->get();
    }

    private function linkedinIntegrationConnections(): Collection
    {
        return IntegrationConnection::query()
            ->whereIn('status', ['active', 'error', 'expired'])
            ->where('account_id', $this->account->id)
            ->whereHas('integration', fn (Builder $integration) => $integration->where('key', 'linkedin'))
            ->when(
                $this->brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $this->brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with(['integration', 'brand'])
            ->latest('created_at')
            ->get();
    }

    private function propertyRecords(): Collection
    {
        return Property::query()
            ->where('account_id', $this->account->id)
            ->when(
                $this->brand !== null,
                fn (Builder $query) => $query->where('brand_id', $this->brand->id),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->withCount(['publishingChannels', 'contentAssets'])
            ->orderBy('name')
            ->get();
    }

    private function channelRecords(): Collection
    {
        return PublishingChannel::query()
            ->where('account_id', $this->account->id)
            ->when(
                $this->brand !== null,
                fn (Builder $query) => $query->where('brand_id', $this->brand->id),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->with('property')
            ->latest('created_at')
            ->get();
    }
}
