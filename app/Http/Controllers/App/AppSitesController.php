<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Events\Notifications\SiteVerified;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\LicenseKey;
use App\Models\Workspace;
use App\Services\Agents\AgentAutomationSettingsResolver;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\Services\Agents\SiteOptimizationOverviewBuilder;
use App\Services\LlmTracking\ArguslyTrackingDefaults;
use App\Services\PluginUpdates\PluginReleaseService;
use App\Services\SubscriptionService;
use App\Services\Sites\SiteApiKeyService;
use App\Services\Sites\SiteConnectivityService;
use App\Support\Interaction\Action;
use App\Support\Interaction\AppInteractionRegistry;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceType;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppSitesController extends Controller
{
    public function index(
        Request $request,
        WorkspaceEntitlementsService $entitlements,
        SubscriptionService $subscriptions
    ): View
    {
        $organization = $request->user()->organization;

        $workspaces = Workspace::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get();

        $workspace = $this->resolveWorkspace($request, $workspaces);

        $sites = ClientSite::query()
            ->with(['workspace', 'siteTokens' => fn ($q) => $q->orderByDesc('created_at')])
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->simplePaginate(15)
            ->withQueryString();
        [$interactionResourcesByKey, $interactionActionsByKey] = $this->resolveSiteIndexInteractionMetadata(
            $sites->getCollection(),
            $workspace
        );

        $limits = $entitlements->limits($workspace);
        $usage = $entitlements->usage($workspace);
        $siteUsage = $entitlements->siteUsage($workspace);
        $hasActiveSubscription = $request->user()
            ? $subscriptions->hasBillingAccessForUser($request->user())
            : false;

        return view('app.sites', [
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'sites' => $sites,
            'limits' => $limits,
            'usage' => $usage,
            'siteUsage' => $siteUsage,
            'hasActiveSubscription' => $hasActiveSubscription,
            'generatedKey' => session('site_plain_key'),
            'generatedSiteId' => session('site_generated_for'),
            'interactionResourcesByKey' => $interactionResourcesByKey,
            'interactionActionsByKey' => $interactionActionsByKey,
        ]);
    }

    /**
     * @param iterable<int, ClientSite> $sites
     * @return array{0: array<string, array>, 1: array<string, array<string, array>>}
     */
    private function resolveSiteIndexInteractionMetadata(iterable $sites, Workspace $workspace): array
    {
        $user = request()->user();
        $sites = collect($sites)->values();
        $resourceRegistry = AppInteractionRegistry::resourceRegistryFor($sites);
        $actionRegistry = AppInteractionRegistry::actionRegistry();

        $resourcesByKey = [];
        $actionsByKey = [];

        foreach ($sites as $site) {
            $resourceKey = ResourceType::SITE.':'.$site->getKey();
            $context = ResourceContext::make([
                'user' => $user,
                'surface' => Action::SURFACE_ROW,
                'page_key' => 'app.sites.index',
                'route_name' => 'app.sites',
                'workspace_id' => $workspace->getKey(),
                'organization_id' => $user?->organization_id,
                'site_id' => $site->getKey(),
                'resource_type' => ResourceType::SITE,
                'resource_id' => $site->getKey(),
                'subject' => $site,
                'metadata' => [
                    'site' => $site,
                    'subject' => $site,
                ],
            ]);

            $resource = $resourceRegistry->resolve($resourceKey, $context);

            if ($resource === null || ! $resource['visible']) {
                continue;
            }

            $resourcesByKey[$resourceKey] = $resource;
            $actionsByKey[$resourceKey] = [];

            foreach ($resource['available_actions'] as $actionKey) {
                if (! $actionRegistry->has($actionKey)) {
                    continue;
                }

                $action = $actionRegistry->resolve($actionKey, $context->toActionContext());

                if ($action['visible'] && $action['method'] === 'GET') {
                    $actionsByKey[$resourceKey][$actionKey] = $action;
                }
            }
        }

        return [$resourcesByKey, $actionsByKey];
    }

    public function store(
        Request $request,
        SiteApiKeyService $keys,
        WorkspaceEntitlementsService $entitlements,
        SubscriptionService $subscriptions,
        ArguslyTrackingDefaults $trackingDefaults,
    ): RedirectResponse {
        Gate::authorize('manage-organization');

        $organization = $request->user()->organization;
        if (! $organization || ! $subscriptions->hasBillingAccessForUser($request->user())) {
            return redirect()
                ->route('app.billing.index')
                ->with('status', 'Complete billing onboarding by starting your subscription before using the app.');
        }

        $workspaceIds = Workspace::query()->where('organization_id', $organization->id)->pluck('id')->all();

        $data = $request->validate([
            'workspace_id' => ['required', Rule::in($workspaceIds)],
            'type' => ['required', Rule::in(ClientSite::allowedTypes())],
            'name' => ['required', 'string', 'max:120'],
            'site_url' => ['required', 'string', 'max:255'],
        ]);

        $workspace = Workspace::query()->whereIn('id', $workspaceIds)->findOrFail($data['workspace_id']);

        try {
            $entitlements->assertCanAddSite($workspace);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['sites' => $exception->getMessage()]);
        }

        $baseUrl = SiteUrl::normalizeBaseUrl((string) $data['site_url']);
        if ($baseUrl === '') {
            return back()->withErrors(['sites' => 'Invalid site URL.']);
        }

        $existing = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->where('base_url', $baseUrl)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            return back()->withErrors(['sites' => 'This site URL is already connected in the selected workspace.']);
        }

        $host = SiteUrl::hostFromUrl($baseUrl);

        $site = ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $request->user()->id,
            'type' => ClientSite::normalizeType((string) $data['type']),
            'name' => (string) $data['name'],
            'site_url' => $baseUrl,
            'base_url' => $baseUrl,
            'allowed_domains' => [$host],
            'is_active' => true,
            'status' => 'pending',
        ]);

        $trackingDefaults->ensureForSite($site);

        [$token, $plain] = $keys->createForSite(
            site: $site,
            scopes: $this->scopesForWorkspace($workspace, $entitlements, $site),
            name: $this->siteKeyName($site)
        );

        $statusMessage = $site->isLaravel()
            ? 'Site added. Complete Laravel connector setup with the generated key.'
            : 'Site added. Complete WordPress plugin setup with the generated key.';

        return redirect()
            ->route('app.sites.show', $site)
            ->with('status', $statusMessage)
            ->with('site_plain_key', $plain)
            ->with('site_generated_for', (string) $site->id);
    }

    public function show(
        Request $request,
        ClientSite $site,
        WorkspaceEntitlementsService $entitlements,
        SiteOptimizationOverviewBuilder $siteOptimizationOverviewBuilder,
        AgentAutomationSettingsResolver $automationSettingsResolver,
    ): View
    {
        $this->assertSiteInOrganization($request, $site);

        $site->load(['workspace', 'siteTokens' => fn ($q) => $q->orderByDesc('created_at')]);

        $workspace = $site->workspace;
        $pluginLicenseKey = $workspace
            ? LicenseKey::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', 'active')
                ->latest('created_at')
                ->first()
            : null;
        $limits = $workspace ? $entitlements->limits($workspace) : [];
        $usage = $workspace ? $entitlements->usage($workspace) : [];
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $siteUsage = [
            'briefs_count' => (int) $site->briefs()
                ->whereBetween('created_at', [$periodStart, $periodEnd])
                ->count(),
            'drafts_count' => (int) $site->drafts()
                ->whereBetween('created_at', [$periodStart, $periodEnd])
                ->count(),
            'wp_pushes_count' => (int) ContentPublishTarget::query()
                ->where('client_site_id', $site->id)
                ->whereNotNull('wp_post_id')
                ->where(function ($query) use ($periodStart, $periodEnd): void {
                    $query->whereBetween('last_synced_at', [$periodStart, $periodEnd])
                        ->orWhere(function ($nested) use ($periodStart, $periodEnd): void {
                            $nested->whereNull('last_synced_at')
                                ->whereBetween('created_at', [$periodStart, $periodEnd]);
                        });
                })
                ->count(),
            'period_label' => now()->format('F Y'),
        ];

        $latestToken = $site->siteTokens->first();
        $optimizationOverview = $siteOptimizationOverviewBuilder->build($site);
        $automationSettings = $automationSettingsResolver->forSite($site);

        return view('app.sites.show', [
            'site' => $site,
            'workspace' => $workspace,
            'limits' => $limits,
            'usage' => $usage,
            'siteUsage' => $siteUsage,
            'latestToken' => $latestToken,
            'optimizationOverview' => $optimizationOverview,
            'automationSettings' => $automationSettings,
            'generatedKey' => session('site_plain_key'),
            'generatedSiteId' => session('site_generated_for'),
            'generatedPluginLicenseKey' => session('plugin_license_plain_key'),
            'generatedPluginLicenseSiteId' => session('plugin_license_generated_for'),
            'pluginLicenseKey' => $pluginLicenseKey,
        ]);
    }

    public function downloadWordPressPlugin(
        PluginReleaseService $pluginReleaseService
    ): StreamedResponse|RedirectResponse {
        $release = $pluginReleaseService->latestRelease();
        if (! $release) {
            return redirect()
                ->route('app.sites')
                ->withErrors(['sites' => 'WordPress plugin is not available for download yet.']);
        }

        $disk = (string) config('argusly.plugin_updates.disk', 'local');
        $path = trim((string) $release->zip_storage_path);

        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return redirect()
                ->route('app.sites')
                ->withErrors(['sites' => 'WordPress plugin release archive is missing.']);
        }

        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            return redirect()
                ->route('app.sites')
                ->withErrors(['sites' => 'Unable to read WordPress plugin archive.']);
        }

        return response()->streamDownload(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            'argusly-wordpress-plugin-' . $release->version . '.zip',
            ['Content-Type' => 'application/zip']
        );
    }

    public function testWordPressConnection(
        Request $request,
        ClientSite $site,
        SiteConnectivityService $connectivity
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertSiteType($site, ClientSite::TYPE_WORDPRESS);

        $result = $connectivity->testWordPressConnection($site);

        if ($result['ok']) {
            SiteVerified::dispatch((string) $site->id, 'wordpress');

            return back()->with('status', 'Connection test succeeded.');
        }

        $statusCode = data_get($result, 'status_code');
        $message = (string) data_get($result, 'body.error', 'Connection test failed.');

        if ($statusCode) {
            $message .= ' (HTTP ' . (int) $statusCode . ')';
        }

        return back()->withErrors(['sites' => $message]);
    }

    public function testLaravelConnector(
        Request $request,
        ClientSite $site,
        SiteConnectivityService $connectivity
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertSiteType($site, ClientSite::TYPE_LARAVEL);

        $result = $connectivity->testLaravelConnector($site);

        if ($result['ok']) {
            SiteVerified::dispatch((string) $site->id, 'laravel');

            return back()->with('status', 'Laravel connector activity check succeeded.');
        }

        $message = (string) data_get($result, 'body.error', 'Laravel connector activity check failed.');
        $statusCode = data_get($result, 'status_code');
        if ($statusCode) {
            $message .= ' (HTTP ' . (int) $statusCode . ')';
        }

        return back()->withErrors(['sites' => $message]);
    }

    public function update(Request $request, ClientSite $site): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $site->name = trim((string) $data['name']);
        $site->save();

        return back()->with('status', 'Site name updated.');
    }

    public function updateAutomationSettings(
        Request $request,
        ClientSite $site,
        AgentAutomationSettingsResolver $settingsResolver,
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);

        $data = $request->validate([
            'smart_suggestions_enabled' => ['nullable', 'boolean'],
            'automatic_recommendation_generation_enabled' => ['nullable', 'boolean'],
            'automatic_refresh_draft_creation_enabled' => ['nullable', 'boolean'],
            'localization_checks_enabled' => ['nullable', 'boolean'],
        ]);

        $settingsResolver->storeSiteSettings($site, [
            'smart_suggestions_enabled' => (bool) ($data['smart_suggestions_enabled'] ?? false),
            'automatic_recommendation_generation_enabled' => (bool) ($data['automatic_recommendation_generation_enabled'] ?? false),
            'automatic_refresh_draft_creation_enabled' => (bool) ($data['automatic_refresh_draft_creation_enabled'] ?? false),
            'localization_checks_enabled' => (bool) ($data['localization_checks_enabled'] ?? false),
        ]);

        return back()->with('status', 'Site automation settings updated.');
    }

    public function regenerateKey(
        Request $request,
        ClientSite $site,
        SiteApiKeyService $keys,
        WorkspaceEntitlementsService $entitlements
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);

        [$token, $plain] = $keys->regenerateForSite(
            site: $site,
            scopes: $this->scopesForWorkspace($site->workspace, $entitlements, $site),
            name: $this->siteKeyName($site)
        );

        return redirect()
            ->route('app.sites.show', $site)
            ->with('status', 'Site key regenerated. Previous keys were revoked.')
            ->with('site_plain_key', $plain)
            ->with('site_generated_for', (string) $site->id);
    }

    public function generatePluginLicenseKey(Request $request, ClientSite $site): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertSiteType($site, ClientSite::TYPE_WORDPRESS);

        $workspace = $site->workspace;
        if (! $workspace) {
            return back()->withErrors(['sites' => 'Workspace not found for this site.']);
        }

        $plain = 'pl_lic_' . bin2hex(random_bytes(24));

        LicenseKey::query()->create([
            'license_key_hash' => hash('sha256', $plain),
            'workspace_id' => $workspace->id,
            'status' => 'active',
            'expires_at' => now()->addYear(),
        ]);

        return redirect()
            ->route('app.sites.show', $site)
            ->with('status', 'WordPress update license key generated. Copy it now; it will only be shown once.')
            ->with('plugin_license_plain_key', $plain)
            ->with('plugin_license_generated_for', (string) $site->id);
    }

    public function toggle(Request $request, ClientSite $site): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);

        if ($site->status === 'disabled') {
            $site->status = 'pending';
            $site->is_active = true;
            $site->disabled_at = null;
        } else {
            $site->status = 'disabled';
            $site->is_active = false;
            $site->disabled_at = now();
        }

        $site->save();

        return back()->with('status', 'Site status updated.');
    }

    public function destroy(Request $request, ClientSite $site): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);

        $hasLinked = $site->briefs()->exists()
            || $site->drafts()->exists()
            || Content::query()->where('client_site_id', $site->id)->exists();

        if ($hasLinked) {
            return back()->withErrors(['sites' => 'This site has linked content. Disable it instead of removing.']);
        }

        $site->siteTokens()->update([
            'revoked' => true,
            'revoked_at' => now(),
        ]);

        $site->delete();

        return redirect()->route('app.sites')->with('status', 'Site removed.');
    }

    private function assertSiteInOrganization(Request $request, ClientSite $site): void
    {
        $organizationId = (int) $request->user()->organization_id;

        if ((int) $site->workspace?->organization_id !== $organizationId) {
            abort(404);
        }
    }

    private function resolveWorkspace(Request $request, $workspaces): Workspace
    {
        $workspaceId = trim((string) $request->query('workspace_id', ''));

        $workspace = $workspaces->firstWhere('id', $workspaceId);

        if (! $workspace) {
            $workspace = $workspaces->first();
        }

        if (! $workspace) {
            abort(422, 'No workspace available for this organization.');
        }

        return $workspace;
    }

    private function scopesForWorkspace(Workspace $workspace, WorkspaceEntitlementsService $entitlements, ?ClientSite $site = null): array
    {
        $limits = $entitlements->limits($workspace);
        $scopes = [
            'briefs:read',
            'heartbeat:write',
            'drafts:read',
        ];

        if ((bool) ($limits['can_generate_briefs'] ?? false)) {
            $scopes[] = 'briefs:write';
        }

        if ((bool) ($limits['can_generate_drafts'] ?? false)) {
            $scopes[] = 'drafts:write';
        }

        $isWordPress = $site ? $site->isWordPress() : true;
        if ($isWordPress && (bool) ($limits['can_push_to_wp'] ?? false)) {
            $scopes[] = 'content:push';
        }

        return array_values(array_unique($scopes));
    }

    private function siteKeyName(ClientSite $site): string
    {
        return $site->isLaravel()
            ? 'Primary Laravel connector key'
            : 'Primary WordPress plugin key';
    }

    private function assertSiteType(ClientSite $site, string $expected): void
    {
        if (ClientSite::normalizeType((string) $site->type) !== $expected) {
            abort(403, 'Action is not allowed for this site type.');
        }
    }
}
