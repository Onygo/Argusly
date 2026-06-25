<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Enums\ContentDestinationType;
use App\Enums\EmailMarketingProvider;
use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Models\ApiWebhook;
use App\Models\ContentDestination;
use App\Models\EmailMarketingConnection;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Integrations\ApiCapabilityService;
use App\Services\Integrations\ApiKeyService;
use App\Services\Integrations\DeveloperCredentialInventoryService;
use App\Services\Integrations\DestinationBillingSiteService;
use App\Services\Integrations\LaravelConnectorDestinationHealthService;
use App\Services\Integrations\LaravelConnectorDestinationConfigurator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppDeveloperController extends Controller
{
    public function index(Request $request, DeveloperCredentialInventoryService $inventory): View
    {
        return $this->renderPage($request, $inventory);
    }

    public function api(Request $request, DeveloperCredentialInventoryService $inventory): View
    {
        return $this->renderPage($request, $inventory, 'keys');
    }

    public function webhooks(Request $request, DeveloperCredentialInventoryService $inventory): View
    {
        return $this->renderPage($request, $inventory, 'webhooks');
    }

    public function docs(Request $request, DeveloperCredentialInventoryService $inventory): View
    {
        return $this->renderPage($request, $inventory, 'docs');
    }

    private function renderPage(
        Request $request,
        DeveloperCredentialInventoryService $inventoryService,
        ?string $forcedTab = null
    ): View
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);

        $inventory = $inventoryService->buildForWorkspace($workspace);
        $workspaceApiKeys = $inventory['workspace_api_keys'];
        $linkedCredentials = $inventory['linked_credentials'];
        $credentialSummary = $inventory['summary'];

        $destinations = ContentDestination::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->with('latestSyncAttempt')
            ->orderByDesc('created_at')
            ->get();

        $webhooks = ApiWebhook::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->get();

        $emailMarketingConnections = EmailMarketingConnection::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->get();

        $usageLogs = ApiRequestLog::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('requested_at')
            ->limit(100)
            ->get();

        return view('app.developer.index', [
            'workspace' => $workspace,
            'destinations' => $destinations,
            'apiKeys' => $workspaceApiKeys,
            'linkedCredentials' => $linkedCredentials,
            'credentialSummary' => $credentialSummary,
            'webhooks' => $webhooks,
            'emailMarketingConnections' => $emailMarketingConnections,
            'emailMarketingProviders' => EmailMarketingProvider::cases(),
            'usageLogs' => $usageLogs,
            'availableScopes' => ApiScopes::all(),
            'createdApiKeySecret' => session('developer.created_api_key_secret'),
            'activeTab' => $forcedTab ?: (trim((string) $request->query('tab', 'overview')) ?: 'overview'),
        ]);
    }

    public function storeDestination(
        Request $request,
        ApiCapabilityService $capabilities,
        LaravelConnectorDestinationConfigurator $configurator,
        DestinationBillingSiteService $billingSiteService,
    ): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);

        $capabilities->assertApiOnlyEnabled($workspace);
        $capabilities->assertCanCreateDestination($workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(ContentDestinationType::values())],
            'environment' => ['nullable', Rule::in(['production', 'staging', 'development'])],
            'default_language' => ['nullable', 'string', 'max:10'],
            'tracking_enabled' => ['nullable', 'boolean'],
            'seo_audit_enabled' => ['nullable', 'boolean'],
            'config.laravel_connector.base_url' => ['nullable', 'url', 'max:2048'],
            'config.laravel_connector.sync_endpoint' => ['nullable', 'string', 'max:255'],
            'config.laravel_connector.site_id' => ['nullable', 'string', 'max:255'],
            'config.laravel_connector.api_key' => ['nullable', 'string', 'max:500'],
            'config.laravel_connector.enabled' => ['nullable', 'boolean'],
            'config.laravel_connector.mode' => ['nullable', Rule::in(['hosted_views', 'headless'])],
        ]);

        $destination = new ContentDestination([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'status' => 'active',
            'environment' => $validated['environment'] ?? 'production',
            'default_language' => $validated['default_language'] ?? 'en',
            'tracking_enabled' => (bool) ($validated['tracking_enabled'] ?? true),
            'seo_audit_enabled' => (bool) ($validated['seo_audit_enabled'] ?? true),
            'created_by' => optional($request->user())->id,
        ]);
        $destination->config = $configurator->mergeConfig($destination, $validated);
        $this->assertLaravelDestinationConfigComplete($destination);
        $destination->save();

        if ($destination->isLaravelConnector()) {
            $billingSiteService->ensureBillingSite($destination->fresh());
        }

        return back()->with('status', 'Destination created.');
    }

    public function updateDestination(
        Request $request,
        ContentDestination $destination,
        LaravelConnectorDestinationConfigurator $configurator,
        DestinationBillingSiteService $billingSiteService,
    ): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);
        abort_if((string) $destination->workspace_id !== (string) $workspace->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'config.laravel_connector.base_url' => ['nullable', 'url', 'max:2048'],
            'config.laravel_connector.sync_endpoint' => ['nullable', 'string', 'max:255'],
            'config.laravel_connector.site_id' => ['nullable', 'string', 'max:255'],
            'config.laravel_connector.api_key' => ['nullable', 'string', 'max:500'],
            'config.laravel_connector.enabled' => ['nullable', 'boolean'],
            'config.laravel_connector.mode' => ['nullable', Rule::in(['hosted_views', 'headless'])],
        ]);

        $destination->fill([
            'name' => $validated['name'],
            'status' => $validated['status'],
        ]);
        $destination->config = $configurator->mergeConfig($destination, $validated);
        $this->assertLaravelDestinationConfigComplete($destination);
        $destination->save();

        if ($destination->isLaravelConnector()) {
            $billingSiteService->ensureBillingSite($destination->fresh());
        }

        return back()->with('status', 'Destination updated.');
    }

    public function testDestinationConnection(
        Request $request,
        ContentDestination $destination,
        LaravelConnectorDestinationHealthService $healthService,
    ): RedirectResponse {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);
        abort_if((string) $destination->workspace_id !== (string) $workspace->id, 404);

        if (! $destination->isLaravelConnector()) {
            return back()->withErrors(['destinations' => 'Only Laravel connector destinations support connection testing.']);
        }

        $result = $healthService->test($destination);

        if ($result['ok']) {
            $message = $result['message'];
            if ($result['status_code']) {
                $message .= ' (HTTP '.(int) $result['status_code'].')';
            }

            return back()->with('status', $message);
        }

        $message = $result['message'] ?: 'Laravel connector connection test failed.';
        if ($result['status_code']) {
            $message .= ' (HTTP '.(int) $result['status_code'].')';
        }

        return back()->withErrors(['destinations' => $message]);
    }

    public function storeEmailMarketingConnection(Request $request): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);

        $validated = $this->validateEmailMarketingConnection($request);

        $connection = new EmailMarketingConnection([
            'workspace_id' => $workspace->id,
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'status' => $validated['status'] ?? 'active',
            'config' => $this->emailMarketingConfig($validated),
            'created_by' => optional($request->user())->id,
        ]);
        $connection->setCredentials([
            'api_key' => (string) data_get($validated, 'credentials.api_key', ''),
        ]);
        $connection->save();

        return back()->with('status', 'Email marketing connection created.');
    }

    public function updateEmailMarketingConnection(Request $request, EmailMarketingConnection $connection): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);
        abort_if((string) $connection->workspace_id !== (string) $workspace->id, 404);

        $validated = $this->validateEmailMarketingConnection($request, updating: true);

        $connection->fill([
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'status' => $validated['status'] ?? 'active',
            'config' => $this->emailMarketingConfig($validated),
        ]);

        $apiKey = trim((string) data_get($validated, 'credentials.api_key', ''));
        if ($apiKey !== '') {
            $connection->setCredentials(['api_key' => $apiKey]);
        }

        $connection->save();

        return back()->with('status', 'Email marketing connection updated.');
    }

    public function storeApiKey(Request $request, ApiKeyService $apiKeyService, ApiCapabilityService $capabilities): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);

        $capabilities->assertApiOnlyEnabled($workspace);
        $capabilities->assertCanCreateApiKey($workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'content_destination_id' => ['nullable', 'uuid'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiScopes::all())],
            'expires_at' => ['nullable', 'date'],
        ]);

        if (! empty($validated['content_destination_id'])) {
            $belongs = $workspace->contentDestinations()->where('id', $validated['content_destination_id'])->exists();
            if (! $belongs) {
                return back()->withErrors(['content_destination_id' => 'Destination not found for this workspace.']);
            }
        }

        $created = $apiKeyService->create(
            workspace: $workspace,
            name: (string) $validated['name'],
            scopes: array_values($validated['scopes']),
            contentDestinationId: $validated['content_destination_id'] ?? null,
            createdBy: optional($request->user())->id,
            expiresAt: isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null,
        );

        return back()
            ->with('status', 'API key created. Save the secret now; it will only be shown once.')
            ->with('developer.created_api_key_secret', $created['plain_text_key']);
    }

    public function revokeApiKey(Request $request, ApiKey $apiKey, ApiKeyService $apiKeyService): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);
        abort_if((string) $apiKey->workspace_id !== (string) $workspace->id, 404);
        if ((bool) $apiKey->is_legacy_import) {
            return back()->withErrors([
                'api_key' => 'This credential is imported from a legacy source and cannot be revoked here. Manage it from its source integration.',
            ]);
        }

        $apiKeyService->revoke($apiKey);

        return back()->with('status', 'API key revoked.');
    }

    public function storeWebhook(Request $request, ApiCapabilityService $capabilities): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);

        $capabilities->assertWebhooksEnabled($workspace);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'content_destination_id' => ['nullable', 'uuid'],
            'target_url' => ['required', 'url', 'max:2048'],
            'secret' => ['required', 'string', 'min:16', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:120'],
        ]);

        if (! empty($validated['content_destination_id'])) {
            $belongs = $workspace->contentDestinations()->where('id', $validated['content_destination_id'])->exists();
            if (! $belongs) {
                return back()->withErrors(['content_destination_id' => 'Destination not found for this workspace.']);
            }
        }

        ApiWebhook::query()->create([
            'workspace_id' => $workspace->id,
            'content_destination_id' => $validated['content_destination_id'] ?? null,
            'name' => $validated['name'],
            'target_url' => $validated['target_url'],
            'secret' => $validated['secret'],
            'events' => array_values($validated['events']),
            'is_active' => true,
            'created_by' => optional($request->user())->id,
        ]);

        return back()->with('status', 'Webhook created.');
    }

    public function updateWebhook(Request $request, ApiWebhook $webhook): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);
        abort_if((string) $webhook->workspace_id !== (string) $workspace->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ]);

        $webhook->update($validated);

        return back()->with('status', 'Webhook updated.');
    }

    public function destroyWebhook(Request $request, ApiWebhook $webhook): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_if(! $workspace, 404);
        abort_if((string) $webhook->workspace_id !== (string) $workspace->id, 404);

        $webhook->delete();

        return back()->with('status', 'Webhook deleted.');
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $organizationId = (int) $request->user()->organization_id;
        if (! $organizationId) {
            return null;
        }

        $impersonatedWorkspaceId = (string) $request->session()->get('impersonated_workspace_id', '');
        if ($impersonatedWorkspaceId !== '') {
            $workspace = Workspace::query()
                ->where('organization_id', $organizationId)
                ->where('id', $impersonatedWorkspaceId)
                ->first();
            if ($workspace) {
                return $workspace;
            }
        }

        return Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->first();
    }

    private function assertLaravelDestinationConfigComplete(ContentDestination $destination): void
    {
        if (! $destination->isLaravelConnector()) {
            return;
        }

        validator([
            'base_url' => $destination->laravelConnectorBaseUrl(),
            'site_id' => $destination->laravelConnectorSiteId(),
            'api_key' => $destination->laravelConnectorApiKey(),
        ], [
            'base_url' => ['required', 'url'],
            'site_id' => ['required', 'string'],
            'api_key' => ['required', 'string'],
        ], [
            'base_url.required' => 'Laravel connector base URL is required.',
            'site_id.required' => 'Laravel connector site ID is required.',
            'api_key.required' => 'Laravel connector API key is required.',
        ])->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEmailMarketingConnection(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', Rule::in(EmailMarketingProvider::values())],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'config.base_url' => ['nullable', 'url', 'max:2048'],
            'config.draft_endpoint' => ['nullable', 'string', 'max:255'],
            'config.default_template_id' => ['nullable', 'string', 'max:255'],
            'config.default_audience_id' => ['nullable', 'string', 'max:255'],
            'config.timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
            'credentials.api_key' => [$updating ? 'nullable' : 'required', 'string', 'max:2000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function emailMarketingConfig(array $validated): array
    {
        return [
            'base_url' => trim((string) data_get($validated, 'config.base_url', '')),
            'draft_endpoint' => trim((string) data_get($validated, 'config.draft_endpoint', '/api/argusly/campaign-snippets')),
            'default_template_id' => trim((string) data_get($validated, 'config.default_template_id', '')),
            'default_audience_id' => trim((string) data_get($validated, 'config.default_audience_id', '')),
            'timeout_seconds' => (int) data_get($validated, 'config.timeout_seconds', 20),
        ];
    }
}
