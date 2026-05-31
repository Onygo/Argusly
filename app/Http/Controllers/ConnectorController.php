<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;
use App\Models\ConnectorInstallation;
use App\Models\ConnectorLog;
use App\Models\ConnectorManifest;
use App\Models\ConnectorToken;
use App\Models\ConnectorVersion;
use App\Models\Property;
use App\Models\PublishingChannel;
use App\Models\User;
use App\Services\DomainEventService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConnectorController extends Controller
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
    ) {}

    public function index(Request $request): View
    {
        [$user, $account, $brand] = $this->context($request);

        return view('app.settings.connectors', [
            'account' => $account,
            'brand' => $brand,
            'manifests' => $this->manifests(),
            'installations' => $this->installations($account, $brand),
            'properties' => $this->properties($account, $brand),
            'channels' => $this->channels($account, $brand),
            'types' => config('connectors.types', []),
            'capabilities' => config('connectors.capabilities', []),
            'tokenAbilities' => ConnectorToken::ABILITIES,
            'user' => $user,
        ]);
    }

    public function storeToken(Request $request, DomainEventService $events): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);
        $installationIds = $this->installations($account, $brand)->pluck('id')->all();

        $validated = $request->validate([
            'connector_installation_id' => ['required', 'integer', Rule::in($installationIds)],
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ConnectorToken::ABILITIES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $installation = $this->installation((int) $validated['connector_installation_id'], $account, $brand);
        $plainToken = ConnectorToken::plainToken();

        $token = ConnectorToken::query()->create([
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'connector_installation_id' => $installation->id,
            'name' => $validated['name'],
            'token_hash' => ConnectorToken::hashToken($plainToken),
            'abilities' => collect($validated['abilities'])->unique()->values()->all(),
            'expires_at' => $validated['expires_at'] ?? null,
            'created_by' => $user->id,
        ]);

        $this->log($installation, 'connector.token_created', 'info', 'created', "Connector token {$token->name} was created.");
        $events->recordForSubject('ConnectorTokenCreated', $token, $user, [
            'connector_installation_id' => $installation->id,
            'abilities' => $token->abilities,
        ]);

        return redirect()
            ->route('settings.connectors')
            ->with('status', 'Connector token created. Copy it now; it will not be shown again.')
            ->with('connector_plain_token', $plainToken);
    }

    public function revokeToken(Request $request, ConnectorToken $token, DomainEventService $events): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);
        $token = $this->token($token->id, $account, $brand);

        if ($token->revoked_at === null) {
            $token->update(['revoked_at' => now()]);

            if ($token->installation) {
                $this->log($token->installation, 'connector.token_revoked', 'warning', 'revoked', "Connector token {$token->name} was revoked.");
            }

            $events->recordForSubject('ConnectorTokenRevoked', $token, $user, [
                'connector_installation_id' => $token->connector_installation_id,
            ]);
        }

        return redirect()
            ->route('settings.connectors')
            ->with('status', 'Connector token revoked.');
    }

    public function rotateToken(Request $request, ConnectorToken $token, DomainEventService $events): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);
        $token = $this->token($token->id, $account, $brand);
        $plainToken = ConnectorToken::plainToken();

        $token->update([
            'token_hash' => ConnectorToken::hashToken($plainToken),
            'last_used_at' => null,
            'revoked_at' => null,
        ]);

        if ($token->installation) {
            $this->log($token->installation, 'connector.token_rotated', 'warning', 'rotated', "Connector token {$token->name} was rotated.");
        }

        $events->recordForSubject('ConnectorTokenRotated', $token, $user, [
            'connector_installation_id' => $token->connector_installation_id,
            'abilities' => $token->abilities,
        ]);

        return redirect()
            ->route('settings.connectors')
            ->with('status', 'Connector token rotated. Copy the new token now; it will not be shown again.')
            ->with('connector_plain_token', $plainToken);
    }

    public function store(Request $request): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);

        $versionIds = ConnectorVersion::query()->active()->pluck('id')->all();
        $propertyIds = $this->properties($account, $brand)->pluck('id')->all();
        $channelIds = $this->channels($account, $brand)->pluck('id')->all();

        $validated = $request->validate([
            'connector_version_id' => ['required', 'integer', Rule::in($versionIds)],
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::in(['account', 'brand'])],
            'property_id' => ['nullable', 'integer', Rule::in($propertyIds)],
            'channel_id' => ['nullable', 'integer', Rule::in($channelIds)],
            'status' => ['required', Rule::in(ConnectorInstallation::STATUSES)],
            'endpoint_url' => ['nullable', 'url', 'max:255'],
            'enabled_capabilities' => ['nullable', 'array'],
            'enabled_capabilities.*' => ['string', Rule::in(config('connectors.capabilities', []))],
        ]);

        /** @var ConnectorVersion $version */
        $version = ConnectorVersion::query()->with('capabilities')->findOrFail($validated['connector_version_id']);
        $enabledCapabilities = $this->allowedCapabilities($version, $validated['enabled_capabilities'] ?? []);
        $brandId = $validated['scope'] === 'brand' ? $brand?->id : null;

        $installation = ConnectorInstallation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brandId,
            'property_id' => $brandId ? ($validated['property_id'] ?? null) : null,
            'channel_id' => $brandId ? ($validated['channel_id'] ?? null) : null,
            'connector_manifest_id' => $version->connector_manifest_id,
            'connector_version_id' => $version->id,
            'installed_by_user_id' => $user->id,
            'name' => $validated['name'],
            'status' => $validated['status'],
            'endpoint_url' => $validated['endpoint_url'] ?? null,
            'enabled_capabilities' => $enabledCapabilities,
        ]);

        $this->log($installation, 'connector.registered', 'info', 'registered');

        return redirect()
            ->route('settings.connectors')
            ->with('status', 'Connector registered.');
    }

    public function update(Request $request, ConnectorInstallation $connector): RedirectResponse
    {
        [$user, $account, $brand] = $this->context($request);
        $installation = $this->installation($connector->id, $account, $brand);

        $validated = $request->validate([
            'status' => ['required', Rule::in(ConnectorInstallation::STATUSES)],
            'last_health_status' => ['nullable', 'string', 'max:255'],
            'last_health_message' => ['nullable', 'string', 'max:1000'],
        ]);

        $health = null;

        if (($validated['last_health_status'] ?? null) || ($validated['last_health_message'] ?? null)) {
            $health = [
                'status' => $validated['last_health_status'] ?? $validated['status'],
                'message' => $validated['last_health_message'] ?? null,
                'checked_by_user_id' => $user->id,
            ];
        }

        $installation->update([
            'status' => $validated['status'],
            'last_health_check' => $health ?? $installation->last_health_check,
            'last_health_checked_at' => $health ? now() : $installation->last_health_checked_at,
            'revoked_at' => $validated['status'] === 'revoked' ? now() : $installation->revoked_at,
        ]);

        $this->log($installation, 'connector.status_updated', 'info', $validated['status'], $health['message'] ?? null);

        return redirect()
            ->route('settings.connectors')
            ->with('status', 'Connector updated.');
    }

    /**
     * @return array{0: User, 1: Account, 2: Brand|null}
     */
    private function context(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $account = $this->currentAccount->get($user) ?? abort(403);
        $brand = $this->currentBrand->get($user);

        return [$user, $account, $brand];
    }

    private function installation(int $id, Account $account, ?Brand $brand): ConnectorInstallation
    {
        return ConnectorInstallation::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->findOrFail($id);
    }

    private function manifests(): Collection
    {
        return ConnectorManifest::query()
            ->where('status', 'active')
            ->with(['versions' => fn ($query) => $query->active()->with('capabilities')])
            ->orderBy('name')
            ->get();
    }

    private function installations(Account $account, ?Brand $brand): Collection
    {
        return ConnectorInstallation::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with([
                'manifest',
                'version',
                'brand',
                'property',
                'channel',
                'tokens' => fn ($query) => $query->latest(),
                'logs' => fn ($query) => $query->latest('occurred_at')->limit(5),
            ])
            ->latest('created_at')
            ->get();
    }

    private function token(int $id, Account $account, ?Brand $brand): ConnectorToken
    {
        return ConnectorToken::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->with('installation')
            ->findOrFail($id);
    }

    private function properties(Account $account, ?Brand $brand): Collection
    {
        if (! $brand) {
            return collect();
        }

        return Property::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->orderBy('name')
            ->get();
    }

    private function channels(Account $account, ?Brand $brand): Collection
    {
        if (! $brand) {
            return collect();
        }

        return PublishingChannel::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    private function allowedCapabilities(ConnectorVersion $version, array $requested): array
    {
        $available = $version->capabilities()
            ->where('is_enabled', true)
            ->pluck('capability')
            ->all();

        return collect($requested)
            ->intersect($available)
            ->values()
            ->all();
    }

    private function log(ConnectorInstallation $installation, string $event, string $level, ?string $status = null, ?string $message = null): void
    {
        ConnectorLog::query()->create([
            'connector_installation_id' => $installation->id,
            'account_id' => $installation->account_id,
            'brand_id' => $installation->brand_id,
            'level' => $level,
            'event' => $event,
            'status' => $status,
            'message' => $message,
            'occurred_at' => now(),
        ]);
    }
}
