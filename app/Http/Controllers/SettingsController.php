<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\BrandMembership;
use App\Models\ConnectorInstallation;
use App\Models\Ga4Property;
use App\Models\IntegrationConnection;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\LlmSetting;
use App\Models\Membership;
use App\Models\Module;
use App\Models\Property;
use App\Models\PublishingChannel;
use App\Models\Role;
use App\Models\SearchConsoleSite;
use App\Models\SubscriptionModule;
use App\Models\User;
use App\Services\Integrations\Google\GoogleAnalyticsAdminService;
use App\Services\Integrations\Google\GoogleProvider;
use App\Services\Integrations\Google\SearchConsoleService;
use App\Services\Integrations\LinkedIn\LinkedInProvider;
use App\Services\LlmSettingsService;
use App\Services\SocialProfiles\SocialProfileService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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

    public function updateAccount(Request $request): RedirectResponse
    {
        $this->resolveContext($request);
        Gate::authorize('update', $this->account);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'default_locale' => ['nullable', 'string', 'max:16'],
            'default_content_language' => ['nullable', 'string', 'max:16'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $settings = $this->account->settings ?? [];

        if (array_key_exists('timezone', $validated)) {
            $settings['timezone'] = $validated['timezone'] ?: null;
        }

        $this->account->update([
            'name' => $validated['name'],
            'default_locale' => $validated['default_locale'] ?: null,
            'default_content_language' => $validated['default_content_language'] ?: null,
            'settings' => array_filter($settings, fn ($value) => $value !== null),
        ]);

        return redirect()->route('settings.account')->with('status', 'Workspace settings updated.');
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

    public function updateBrand(Request $request, Brand $brand): RedirectResponse
    {
        $this->resolveContext($request);
        $brand = $this->brandRecord($brand->id);
        Gate::authorize('update', $brand);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('brands', 'slug')->where('account_id', $this->account->id)->ignore($brand->id),
            ],
            'domain' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'description' => ['nullable', 'string'],
            'market' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:16'],
            'default_content_language' => ['nullable', 'string', 'max:16'],
            'enabled_content_languages' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'in:active,inactive,archived'],
        ]);

        $brand->update([
            'name' => $validated['name'],
            'slug' => str($validated['slug'])->slug()->toString(),
            'domain' => $validated['domain'] ?: null,
            'website_url' => $validated['website_url'] ?: null,
            'description' => $validated['description'] ?: null,
            'market' => $validated['market'] ?: null,
            'language' => $validated['language'] ?: null,
            'default_content_language' => $validated['default_content_language'] ?: null,
            'enabled_content_languages' => $this->languageList($validated['enabled_content_languages'] ?? null),
            'status' => $validated['status'],
        ]);

        return redirect()->route('settings.brands')->with('status', 'Brand settings updated.');
    }

    public function team(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.team', [
            'account' => $this->account,
            'brand' => $this->brand,
            'accountMembers' => $this->accountMembers(),
            'brandMembers' => $this->brandMembers(),
            'roles' => $this->assignableRoles(),
            'brandAssignableMembers' => $this->brandAssignableMembers(),
        ]);
    }

    public function updateMembership(Request $request, Membership $membership): RedirectResponse
    {
        $this->resolveContext($request);
        $membership = $this->accountMembership($membership->id);
        Gate::authorize('update', $membership);

        $roleIds = $this->assignableRoles()->pluck('id')->all();
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
            'role_id' => ['required', 'integer', Rule::in($roleIds)],
        ]);

        $membership->update([
            'status' => $validated['status'],
            'joined_at' => $validated['status'] === 'active' ? ($membership->joined_at ?? now()) : $membership->joined_at,
        ]);

        $this->syncRole($membership->user, (int) $validated['role_id'], $this->account, null);

        return redirect()->route('settings.team')->with('status', 'Workspace member updated.');
    }

    public function storeBrandMembership(Request $request): RedirectResponse
    {
        $this->resolveContext($request);
        abort_unless($this->brand, 403);
        Gate::authorize('create', BrandMembership::class);

        $userIds = $this->account->memberships()->where('status', 'active')->pluck('user_id')->all();
        $roleIds = $this->assignableRoles()->pluck('id')->all();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($userIds)],
            'role_id' => ['required', 'integer', Rule::in($roleIds)],
        ]);

        $membership = BrandMembership::query()->updateOrCreate(
            [
                'user_id' => $validated['user_id'],
                'brand_id' => $this->brand->id,
            ],
            [
                'account_id' => $this->account->id,
                'status' => 'active',
                'joined_at' => now(),
            ],
        );

        $this->syncRole($membership->user, (int) $validated['role_id'], $this->account, $this->brand);

        return redirect()->route('settings.team')->with('status', 'Brand member assigned.');
    }

    public function updateBrandMembership(Request $request, BrandMembership $membership): RedirectResponse
    {
        $this->resolveContext($request);
        $membership = $this->brandMembership($membership->id);
        Gate::authorize('update', $membership);

        $roleIds = $this->assignableRoles()->pluck('id')->all();
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
            'role_id' => ['required', 'integer', Rule::in($roleIds)],
        ]);

        $membership->update([
            'status' => $validated['status'],
            'joined_at' => $validated['status'] === 'active' ? ($membership->joined_at ?? now()) : $membership->joined_at,
        ]);

        $this->syncRole($membership->user, (int) $validated['role_id'], $this->account, $membership->brand);

        return redirect()->route('settings.team')->with('status', 'Brand member updated.');
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

    public function llm(Request $request): View
    {
        $this->resolveContext($request);

        return view('app.settings.llm', [
            'account' => $this->account,
            'brand' => $this->brand,
            'providers' => LlmProvider::query()->where('status', 'active')->withCount(['models' => fn (Builder $query) => $query->where('status', 'active')])->orderBy('name')->get(),
            'models' => LlmModel::query()->with('provider')->where('status', 'active')->whereHas('provider', fn (Builder $query) => $query->where('status', 'active'))->orderBy('name')->get(),
            'accountSetting' => LlmSetting::query()
                ->where('account_id', $this->account->id)
                ->whereNull('brand_id')
                ->with(['defaultProvider', 'defaultModel', 'fallbackProvider', 'fallbackModel'])
                ->first(),
            'brandSetting' => $this->brand
                ? LlmSetting::query()
                    ->where('account_id', $this->account->id)
                    ->where('brand_id', $this->brand->id)
                    ->with(['defaultProvider', 'defaultModel', 'fallbackProvider', 'fallbackModel'])
                    ->first()
                : null,
        ]);
    }

    public function updateLlm(Request $request, LlmSettingsService $settings): RedirectResponse
    {
        $this->resolveContext($request);

        $validated = $request->validate([
            'scope' => ['required', 'in:account,brand'],
            'default_provider_id' => ['nullable', 'integer', 'exists:llm_providers,id'],
            'default_model_id' => ['nullable', 'integer', 'exists:llm_models,id'],
            'fallback_provider_id' => ['nullable', 'integer', 'exists:llm_providers,id'],
            'fallback_model_id' => ['nullable', 'integer', 'exists:llm_models,id'],
            'temperature' => ['nullable', 'numeric', 'between:0,2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'allowed_providers' => ['nullable', 'string', 'max:500'],
            'denied_providers' => ['nullable', 'string', 'max:500'],
            'allowed_models' => ['nullable', 'string', 'max:1000'],
            'denied_models' => ['nullable', 'string', 'max:1000'],
            'monthly_credit_budget' => ['nullable', 'integer', 'min:0'],
            'brand_monthly_credit_budget' => ['nullable', 'integer', 'min:0'],
            'user_monthly_credit_budget' => ['nullable', 'integer', 'min:0'],
        ]);
        $validated = $this->nullableLlmAttributes($validated);

        $this->validateActiveLlmPair($validated['default_provider_id'] ?? null, $validated['default_model_id'] ?? null);
        $this->validateActiveLlmPair($validated['fallback_provider_id'] ?? null, $validated['fallback_model_id'] ?? null);

        $attributes = [
            'default_provider_id' => $validated['default_provider_id'] ?? null,
            'default_model_id' => $validated['default_model_id'] ?? null,
            'fallback_provider_id' => $validated['fallback_provider_id'] ?? null,
            'fallback_model_id' => $validated['fallback_model_id'] ?? null,
            'temperature' => $validated['temperature'] ?? null,
            'max_tokens' => $validated['max_tokens'] ?? null,
            'settings' => $this->llmPolicySettings($validated),
        ];

        if ($validated['scope'] === 'brand') {
            abort_unless($this->brand, 403);
            $settings->upsertBrand($this->account, $this->brand, $attributes);

            return back()->with('status', 'Brand LLM settings updated.');
        }

        $settings->upsertAccount($this->account, $attributes);

        return back()->with('status', 'Account LLM settings updated.');
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
    ): RedirectResponse {
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
    ): View {
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
            'statuses' => Property::STATUSES,
        ]);
    }

    public function storeProperty(Request $request): RedirectResponse
    {
        $this->resolveContext($request);
        abort_unless($this->brand, 403);
        Gate::authorize('create', Property::class);

        $validated = $this->propertyAttributes($request);

        Property::query()->create($validated + [
            'account_id' => $this->account->id,
            'brand_id' => $this->brand->id,
        ]);

        return redirect()->route('settings.properties')->with('status', 'Property created.');
    }

    public function updateProperty(Request $request, Property $property): RedirectResponse
    {
        $this->resolveContext($request);
        $property = $this->property($property->id);
        Gate::authorize('update', $property);

        $property->update($this->propertyAttributes($request));

        return redirect()->route('settings.properties')->with('status', 'Property updated.');
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

    private function validateActiveLlmPair(mixed $providerId, mixed $modelId): void
    {
        if ($providerId === null || $providerId === '' || $modelId === null || $modelId === '') {
            return;
        }

        $provider = LlmProvider::query()->where('status', 'active')->find((int) $providerId);
        $model = LlmModel::query()->where('status', 'active')->find((int) $modelId);

        abort_if(! $provider || ! $model || $model->provider_id !== $provider->id, 422, 'The model must belong to the selected active provider.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function nullableLlmAttributes(array $attributes): array
    {
        foreach (['default_provider_id', 'default_model_id', 'fallback_provider_id', 'fallback_model_id', 'temperature', 'max_tokens'] as $key) {
            if (($attributes[$key] ?? null) === '') {
                $attributes[$key] = null;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function llmPolicySettings(array $attributes): ?array
    {
        $settings = [
            'allowed_providers' => $this->commaList($attributes['allowed_providers'] ?? null),
            'denied_providers' => $this->commaList($attributes['denied_providers'] ?? null),
            'allowed_models' => $this->commaList($attributes['allowed_models'] ?? null),
            'denied_models' => $this->commaList($attributes['denied_models'] ?? null),
            'monthly_credit_budget' => $this->nullablePositiveInt($attributes['monthly_credit_budget'] ?? null),
            'brand_monthly_credit_budget' => $this->nullablePositiveInt($attributes['brand_monthly_credit_budget'] ?? null),
            'user_monthly_credit_budget' => $this->nullablePositiveInt($attributes['user_monthly_credit_budget'] ?? null),
        ];

        $settings = array_filter($settings, fn ($value) => $value !== null && $value !== []);

        return $settings === [] ? null : $settings;
    }

    /**
     * @return array<int, string>
     */
    private function commaList(mixed $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function accountMembers(): Collection
    {
        return $this->account->memberships()
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn ($membership) => [
                'user' => $membership->user,
                'membership' => $membership,
                'status' => $membership->status,
                'role' => $this->roleForUser($membership->user, $this->account, null),
                'role_id' => $this->roleAssignmentForUser($membership->user, $this->account, null)?->role_id,
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
                'membership' => $membership,
                'status' => $membership->status,
                'role' => $this->roleForUser($membership->user, $this->account, $this->brand),
                'role_id' => $this->roleAssignmentForUser($membership->user, $this->account, $this->brand)?->role_id,
                'joined_at' => $membership->joined_at,
            ]);
    }

    private function roleForUser(User $user, Account $account, ?Brand $brand): ?string
    {
        return $this->roleAssignmentForUser($user, $account, $brand)
            ?->role
            ?->display_name;
    }

    private function roleAssignmentForUser(User $user, Account $account, ?Brand $brand)
    {
        return $user->roleAssignments()
            ->where('account_id', $account->id)
            ->when($brand, fn (Builder $query) => $query->where('brand_id', $brand->id), fn (Builder $query) => $query->whereNull('brand_id'))
            ->whereHas('role')
            ->with('role')
            ->get()
            ->sortByDesc(fn ($assignment) => $assignment->role->priority)
            ->first();
    }

    private function assignableRoles(): Collection
    {
        return Role::query()
            ->where('name', '!=', 'platform_admin')
            ->orderByDesc('priority')
            ->orderBy('display_name')
            ->get();
    }

    private function brandAssignableMembers(): Collection
    {
        if (! $this->brand) {
            return collect();
        }

        $assignedUserIds = $this->brand->memberships()->pluck('user_id')->all();

        return $this->account->memberships()
            ->where('status', 'active')
            ->whereNotIn('user_id', $assignedUserIds)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->pluck('user')
            ->filter()
            ->values();
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

    private function brandRecord(int $id): Brand
    {
        return Brand::query()
            ->where('account_id', $this->account->id)
            ->findOrFail($id);
    }

    private function accountMembership(int $id): Membership
    {
        return Membership::query()
            ->where('account_id', $this->account->id)
            ->with('user')
            ->findOrFail($id);
    }

    private function brandMembership(int $id): BrandMembership
    {
        return BrandMembership::query()
            ->where('account_id', $this->account->id)
            ->when($this->brand, fn (Builder $query) => $query->where('brand_id', $this->brand->id), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->with(['user', 'brand'])
            ->findOrFail($id);
    }

    private function property(int $id): Property
    {
        return Property::query()
            ->where('account_id', $this->account->id)
            ->when($this->brand, fn (Builder $query) => $query->where('brand_id', $this->brand->id), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->findOrFail($id);
    }

    /**
     * @return array{name: string, type: string, url: string, primary_language?: string|null, status: string}
     */
    private function propertyAttributes(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Property::TYPES)],
            'url' => ['required', 'url', 'max:2048'],
            'primary_language' => ['nullable', 'string', 'max:16'],
            'status' => ['required', Rule::in(Property::STATUSES)],
        ]);

        $validated['primary_language'] = $validated['primary_language'] ?: null;

        return $validated;
    }

    private function syncRole(User $user, int $roleId, Account $account, ?Brand $brand): void
    {
        $user->roleAssignments()
            ->where('account_id', $account->id)
            ->when($brand, fn (Builder $query) => $query->where('brand_id', $brand->id), fn (Builder $query) => $query->whereNull('brand_id'))
            ->delete();

        $user->roleAssignments()->create([
            'role_id' => $roleId,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
        ]);
    }

    /**
     * @return array<int, string>|null
     */
    private function languageList(?string $languages): ?array
    {
        $items = collect(explode(',', (string) $languages))
            ->map(fn (string $language) => trim($language))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $items === [] ? null : $items;
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
