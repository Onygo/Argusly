<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\Ga4Property;
use App\Models\IntegrationConnection;
use App\Models\Module;
use App\Models\Property;
use App\Models\PublishingChannel;
use App\Models\SearchConsoleSite;
use App\Models\SubscriptionModule;
use App\Models\User;
use App\Services\Integrations\Google\GoogleAnalyticsAdminService;
use App\Services\Integrations\Google\GoogleProvider;
use App\Services\Integrations\Google\SearchConsoleService;
use App\Services\Integrations\LinkedIn\LinkedInProvider;
use App\Services\SocialProfiles\SocialProfileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

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
            'googleProvider' => app(GoogleProvider::class),
            'googleConnections' => $this->googleIntegrationConnections(),
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

    public function googleAnalyticsIntegration(
        Request $request,
        GoogleProvider $google,
        GoogleAnalyticsAdminService $analyticsAdmin,
    ): View {
        $this->resolveContext($request);

        $connections = $this->googleAnalyticsConnections();

        return view('app.settings.integrations.google-analytics', [
            'account' => $this->account,
            'brand' => $this->brand,
            'connections' => $connections,
            'properties' => $this->ga4PropertyRecords(),
            'brandProperties' => $this->propertyRecords(),
            'discovery' => $this->brand ? $analyticsAdmin->discoverForConnections($connections) : collect(),
            'provider' => $google,
        ]);
    }

    public function storeGoogleAnalyticsProperties(
        Request $request,
        GoogleAnalyticsAdminService $analyticsAdmin,
    ): RedirectResponse
    {
        $this->resolveContext($request);

        abort_unless($this->brand, 403);

        $connections = $this->googleAnalyticsConnections()->pluck('id')->all();
        $brandPropertyIds = $this->propertyRecords()->pluck('id')->all();

        $validated = $request->validate([
            'integration_connection_id' => ['required', 'integer', Rule::in($connections)],
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['required', 'string'],
            'property_map' => ['nullable', 'array'],
            'property_map.*' => ['nullable', 'integer', Rule::in($brandPropertyIds)],
        ]);

        $connection = IntegrationConnection::query()
            ->with('integration')
            ->where('account_id', $this->account->id)
            ->when($this->brand, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('brand_id')
                ->orWhere('brand_id', $this->brand->id)))
            ->findOrFail((int) $validated['integration_connection_id']);

        try {
            $stored = $analyticsAdmin->storeSelectedProperties(
                $connection,
                $this->account,
                $this->brand,
                collect($validated['selected'])
                    ->map(fn (string $name) => [
                        'name' => $name,
                        'property_id' => $validated['property_map'][$name] ?? null,
                    ])
                    ->all(),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return redirect()
                ->route('settings.integrations.google-analytics')
                ->with('google_error', $exception->getMessage());
        }

        return redirect()
            ->route('settings.integrations.google-analytics')
            ->with('google_status', "{$stored->count()} GA4 ".str('property')->plural($stored->count()).' selected.');
    }

    public function searchConsoleIntegration(
        Request $request,
        GoogleProvider $google,
        SearchConsoleService $searchConsole,
    ): View
    {
        $this->resolveContext($request);
        $connections = $this->searchConsoleConnections();

        return view('app.settings.integrations.search-console', [
            'account' => $this->account,
            'brand' => $this->brand,
            'connections' => $connections,
            'sites' => $this->searchConsoleSiteRecords(),
            'discovery' => $this->brand ? $searchConsole->discoverForConnections($connections) : collect(),
            'provider' => $google,
        ]);
    }

    public function storeSearchConsoleSites(
        Request $request,
        SearchConsoleService $searchConsole,
    ): RedirectResponse {
        $this->resolveContext($request);

        abort_unless($this->brand, 403);

        $connections = $this->searchConsoleConnections()
            ->filter(fn (IntegrationConnection $connection) => $connection->integration?->key === 'google')
            ->pluck('id')
            ->all();

        $validated = $request->validate([
            'integration_connection_id' => ['required', 'integer', Rule::in($connections)],
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['required', 'string'],
        ]);

        $connection = IntegrationConnection::query()
            ->with('integration')
            ->where('account_id', $this->account->id)
            ->when($this->brand, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereNull('brand_id')
                ->orWhere('brand_id', $this->brand->id)))
            ->findOrFail((int) $validated['integration_connection_id']);

        try {
            $stored = $searchConsole->storeSelectedSites($connection, $this->account, $this->brand, $validated['selected']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return redirect()
                ->route('settings.integrations.search-console')
                ->with('google_error', $exception->getMessage());
        }

        return redirect()
            ->route('settings.integrations.search-console')
            ->with('google_status', "{$stored->count()} Search Console ".str('site')->plural($stored->count()).' selected.');
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
            'connectorInstallations' => $this->connectorInstallationRecords(),
            'providers' => PublishingChannel::PROVIDERS,
        ]);
    }

    public function updateChannel(Request $request, PublishingChannel $channel): RedirectResponse
    {
        $this->resolveContext($request);

        $channel = $this->channel($channel->id);
        $connectorIds = $this->connectorInstallationRecords($channel)->pluck('id')->all();

        $validated = $request->validate([
            'connector_installation_id' => ['nullable', 'integer', Rule::in($connectorIds)],
        ]);

        $channel->update([
            'connector_installation_id' => $validated['connector_installation_id'] ?? null,
        ]);

        return redirect()
            ->route('settings.channels')
            ->with('status', 'Publishing channel connector updated.');
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

    private function googleIntegrationConnections(): Collection
    {
        return IntegrationConnection::query()
            ->whereIn('status', ['active', 'error', 'expired', 'revoked'])
            ->where('account_id', $this->account->id)
            ->whereHas('integration', fn (Builder $integration) => $integration->where('key', 'google'))
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

    private function googleAnalyticsConnections(): Collection
    {
        return IntegrationConnection::query()
            ->whereIn('status', ['active', 'error', 'expired', 'revoked'])
            ->where('account_id', $this->account->id)
            ->whereHas('integration', fn (Builder $integration) => $integration->whereIn('key', ['google', 'google_analytics']))
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

    private function searchConsoleConnections(): Collection
    {
        return IntegrationConnection::query()
            ->whereIn('status', ['active', 'error', 'expired', 'revoked'])
            ->where('account_id', $this->account->id)
            ->whereHas('integration', fn (Builder $integration) => $integration->whereIn('key', ['google', 'google_search_console']))
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

    private function ga4PropertyRecords(): Collection
    {
        if (! $this->brand) {
            return collect();
        }

        return Ga4Property::query()
            ->where('account_id', $this->account->id)
            ->where('brand_id', $this->brand->id)
            ->with(['integrationConnection.integration', 'property'])
            ->withCount('metricSnapshots')
            ->orderBy('display_name')
            ->get();
    }

    private function searchConsoleSiteRecords(): Collection
    {
        if (! $this->brand) {
            return collect();
        }

        return SearchConsoleSite::query()
            ->where('account_id', $this->account->id)
            ->where('brand_id', $this->brand->id)
            ->with(['integrationConnection.integration'])
            ->withCount('querySnapshots')
            ->orderBy('site_url')
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
            ->with(['property', 'connectorInstallation.manifest', 'connectorInstallation.version'])
            ->latest('created_at')
            ->get();
    }

    private function channel(int $id): PublishingChannel
    {
        return PublishingChannel::query()
            ->where('account_id', $this->account->id)
            ->when(
                $this->brand !== null,
                fn (Builder $query) => $query->where('brand_id', $this->brand->id),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->findOrFail($id);
    }

    private function connectorInstallationRecords(?PublishingChannel $channel = null): Collection
    {
        if (! $this->brand) {
            return collect();
        }

        return ConnectorInstallation::query()
            ->where('account_id', $this->account->id)
            ->where('brand_id', $this->brand->id)
            ->when(
                $channel !== null,
                fn (Builder $query) => $query
                    ->where(fn (Builder $scope) => $scope
                        ->whereNull('property_id')
                        ->orWhere('property_id', $channel->property_id))
                    ->whereHas('manifest', fn (Builder $manifest) => $manifest->where('type', $channel->provider)),
            )
            ->with(['manifest', 'version'])
            ->orderBy('name')
            ->get();
    }
}
