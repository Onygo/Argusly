<?php

namespace App\Services\DataConnectors;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorOAuthState;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class GenericOAuthConnectorDriver implements ConnectorProviderDriver
{
    public function __construct(
        private readonly string $providerKey,
        private readonly DataConnectorRegistry $registry,
        private readonly ConnectorOAuthAuthorizationUrlGenerator $authorizations,
        private readonly ConnectorOAuthStateService $states,
        private readonly ConnectorOAuthTokenManager $tokens,
        private readonly ConnectorTokenVault $vault,
        private readonly ConnectorScopeSynchronizer $scopes,
        private readonly ConnectorDatasetDiscoveryService $discovery,
        private readonly ConnectorSyncEngine $sync,
        private readonly ConnectorHealthCheckService $healthChecks,
        private readonly ConnectorHealthService $health,
        private readonly ConnectorAuditLogger $audit,
    ) {}

    public function authorize(Workspace $workspace, User $user, ?ConnectorAccount $account = null): ConnectorOAuthAuthorization
    {
        $provider = $this->providerRecord();
        $definition = $this->definition();
        $scopes = (array) data_get($definition, 'config_json.oauth.scopes', data_get($definition, 'config_json.required_scopes', []));

        return $this->authorizations->generate($this->providerKey, [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'connector_provider_id' => $provider->id,
            'connector_account_id' => $account?->id,
            'scopes' => $scopes,
            'action' => $account instanceof ConnectorAccount ? 'reconnect' : 'connect',
        ]);
    }

    /**
     * @return array{account: ConnectorAccount, datasets: array<int, mixed>}
     */
    public function callback(string $state, string $code, ?User $user = null): array
    {
        $stateRecord = $this->states->consume($state);
        $this->assertStateBelongsToDriver($stateRecord);

        if ($user instanceof User && $stateRecord->user_id !== null && (int) $stateRecord->user_id !== (int) $user->id) {
            throw new RuntimeException('OAuth state belongs to another user.');
        }

        $provider = $this->providerRecord();
        $workspace = Workspace::query()->findOrFail($stateRecord->workspace_id);
        $account = $this->resolveAccount($stateRecord, $workspace, $provider);
        $before = $account->wasRecentlyCreated ? null : $account->attributesToArray();

        $token = $this->tokens->exchangeAndStore(
            account: $account,
            state: $stateRecord,
            code: $code,
            oauthConfig: $this->oauthConfig(),
        );

        $requestedScopes = (array) ($stateRecord->scopes_json ?? []);
        $grantedScopes = $this->grantedScopes($token, $requestedScopes);
        $this->scopes->sync($account, $requestedScopes, $grantedScopes);

        $account->forceFill([
            'status' => ConnectorAccount::STATUS_CONNECTED,
            'connected_at' => $account->connected_at ?: now(),
            'disconnected_at' => null,
            'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
            'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
            'health_score' => 100,
            'last_error' => null,
            'metadata_json' => array_merge((array) ($account->metadata_json ?? []), [
                'oauth_connected_by_user_id' => $stateRecord->user_id,
                'oauth_state_id' => $stateRecord->id,
                'last_oauth_callback_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $datasets = [];

        try {
            $discovered = $this->discovery->discover($account);
            $datasets = $discovered['datasets'];
            $this->refreshAccountIdentityFromDatasets($account, $datasets);
        } catch (Throwable $exception) {
            $this->health->record(
                account: $account,
                severity: ConnectorHealthEvent::SEVERITY_WARNING,
                eventType: 'dataset.discovery_deferred',
                message: 'Connector connected, but dataset discovery needs a retry.',
                context: [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );
        }

        $this->audit->record(
            subject: $account,
            action: $before ? 'connector.reconnected' : 'connector.connected',
            before: $before,
            after: array_merge($account->fresh()->attributesToArray(), [
                'granted_scopes' => $grantedScopes,
            ]),
            actor: $user,
        );

        return ['account' => $account->fresh(), 'datasets' => $datasets];
    }

    /**
     * @return array<string, mixed>
     */
    public function discoverDatasets(ConnectorAccount $account): array
    {
        return $this->discovery->discover($account);
    }

    public function sync(ConnectorAccount $account, string $runType = 'manual'): int
    {
        $count = 0;

        $account->datasets()
            ->where('status', \App\Models\Connectors\ConnectorDataset::STATUS_ACTIVE)
            ->orderBy('display_name')
            ->get()
            ->each(function ($dataset) use ($runType, &$count): void {
                $this->sync->sync(ConnectorSyncPlan::forDataset($dataset, $runType));
                $count++;
            });

        $this->audit->record($account, 'connector.sync_requested', null, [
            'workspace_id' => $account->workspace_id,
            'provider_key' => $account->provider_key,
            'datasets' => $count,
        ]);

        return $count;
    }

    public function health(ConnectorAccount $account): ConnectorHealthEvent
    {
        return $this->healthChecks->check($account);
    }

    public function refresh(ConnectorAccount $account): ConnectorToken
    {
        return app(ConnectorAccessTokenService::class)->refresh($account);
    }

    public function disconnect(ConnectorAccount $account, ?User $user = null): void
    {
        $before = $account->attributesToArray();
        $token = $this->vault->latestFor($account);

        if ($token instanceof ConnectorToken && $token->revoked_at === null) {
            $this->tokens->revoke($token, $this->oauthConfig());
        }

        $account->forceFill([
            'status' => ConnectorAccount::STATUS_REVOKED,
            'disconnected_at' => now(),
            'next_sync_at' => null,
            'last_error' => null,
            'health_score' => 0,
        ])->save();

        $account->datasets()->update([
            'status' => \App\Models\Connectors\ConnectorDataset::STATUS_DISABLED,
            'next_sync_at' => null,
            'updated_at' => now(),
        ]);

        $this->health->record(
            account: $account,
            severity: ConnectorHealthEvent::SEVERITY_INFO,
            eventType: ConnectorHealthEvent::EVENT_DISABLED,
            message: 'Connector disconnected.',
        );

        $this->audit->record($account, 'connector.disconnected', $before, $account->fresh()->attributesToArray(), $user);
    }

    private function resolveAccount(ConnectorOAuthState $state, Workspace $workspace, ConnectorProvider $provider): ConnectorAccount
    {
        if ($state->connector_account_id) {
            $account = ConnectorAccount::query()
                ->whereKey($state->connector_account_id)
                ->where('workspace_id', $workspace->id)
                ->firstOrFail();

            $account->forceFill([
                'connector_provider_id' => $provider->id,
                'provider_key' => $this->providerKey,
            ])->save();

            return $account;
        }

        return ConnectorAccount::query()->create([
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'connector_provider_id' => $provider->id,
            'provider_key' => $this->providerKey,
            'account_name' => $provider->name.' Account',
            'external_account_id' => null,
            'status' => ConnectorAccount::STATUS_DRAFT,
            'metadata_json' => [],
        ]);
    }

    /**
     * @param array<int, mixed> $datasets
     */
    private function refreshAccountIdentityFromDatasets(ConnectorAccount $account, array $datasets): void
    {
        $first = collect($datasets)->first();

        if (! $first) {
            return;
        }

        $account->forceFill([
            'account_name' => (string) ($first->display_name ?? $account->account_name),
            'external_account_id' => (string) ($first->external_dataset_id ?? $account->external_account_id),
        ])->save();
    }

    /**
     * @return list<string>
     */
    private function grantedScopes(ConnectorToken $token, array $requestedScopes): array
    {
        $scope = $token->rotation_metadata_json['scope'] ?? null;

        if (is_string($scope) && trim($scope) !== '') {
            return array_values(array_filter(explode(' ', $scope)));
        }

        if (is_array($scope)) {
            return array_values(array_filter($scope, fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
        }

        return array_values(array_filter($requestedScopes, fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
    }

    private function assertStateBelongsToDriver(ConnectorOAuthState $state): void
    {
        if ((string) $state->provider_key !== $this->providerKey) {
            throw new RuntimeException('OAuth state belongs to another connector provider.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return $this->registry->provider($this->providerKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthConfig(): array
    {
        return (array) data_get($this->definition(), 'config_json.oauth', []);
    }

    private function providerRecord(): ConnectorProvider
    {
        $definition = $this->definition();

        return ConnectorProvider::query()->updateOrCreate(
            ['provider_key' => $this->providerKey],
            [
                'name' => (string) $definition['name'],
                'category' => (string) ($definition['category'] ?? ConnectorProvider::CATEGORY_OTHER),
                'status' => (string) ($definition['status'] ?? ConnectorProvider::STATUS_ACTIVE),
                'config_json' => Arr::get($definition, 'config_json', []),
                'supports_oauth' => (bool) ($definition['supports_oauth'] ?? true),
                'supports_sync' => (bool) ($definition['supports_sync'] ?? true),
                'supports_webhooks' => (bool) ($definition['supports_webhooks'] ?? false),
            ],
        );
    }
}
