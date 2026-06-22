<?php

namespace App\Http\Controllers\App\Settings;

use App\Enums\DistributionChannelType;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Models\DistributionChannel;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\Instagram\InstagramClient;
use App\Services\SocialDistribution\SocialDistributionAuditLogger;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

class InstagramIntegrationController extends Controller
{
    private const PROFESSIONAL_TYPES = ['business', 'creator'];

    private const PERSONAL_ACCOUNT_MESSAGE = 'Instagram publishing is alleen beschikbaar voor Business en Creator accounts. Zet je Instagram account om naar een professioneel account om dit kanaal te gebruiken.';

    public function show(Request $request): View
    {
        $workspace = $this->workspace($request);
        $accounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', SocialPlatform::INSTAGRAM->value)
            ->with('user')
            ->latest('connected_at')
            ->latest()
            ->get();

        return view('app.settings.integrations.instagram', [
            'workspace' => $workspace,
            'accounts' => $accounts,
            'instagramEnabled' => (bool) config('services.meta.enabled'),
            'canManageInstagram' => $this->canManageInstagram($request),
            'configuredRedirectUri' => (string) config('services.meta.redirect_uri'),
        ]);
    }

    public function connect(Request $request, InstagramClient $client): RedirectResponse
    {
        if (! $this->canManageInstagram($request)) {
            return redirect()->route('app.settings.integrations.instagram', ['workspace_id' => $request->query('workspace_id')])
                ->withErrors(['instagram' => 'Only workspace owners and admins can connect an Instagram account.']);
        }

        if (! (bool) config('services.meta.enabled')) {
            return redirect()->route('app.settings.integrations.instagram', ['workspace_id' => $request->query('workspace_id')])
                ->withErrors(['instagram' => 'Meta OAuth is not enabled yet. Add the Meta app credentials and enable the integration first.']);
        }

        $state = Str::random(48);
        $workspace = $this->workspace($request);
        $request->session()->put('instagram_oauth_state', $state);
        $request->session()->put('instagram_oauth_workspace_id', (string) $workspace->id);
        $request->session()->put('instagram_oauth_owner_user_id', (int) $request->user()->id);

        try {
            return redirect()->away($client->authorizationUrl($state));
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('app.settings.integrations.instagram', ['workspace_id' => $workspace->id])
                ->withErrors(['instagram' => $exception->getMessage()]);
        }
    }

    public function callback(Request $request, InstagramClient $client, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManageInstagram($request), 403);

        $workspaceId = (string) $request->session()->get('instagram_oauth_workspace_id', $request->query('workspace_id', ''));

        if ($request->filled('error')) {
            return redirect()->route('app.settings.integrations.instagram', $this->workspaceRouteParams($workspaceId))
                ->withErrors(['instagram' => 'Instagram access was not granted: '.$request->query('error_description', $request->query('error'))]);
        }

        $expectedState = (string) $request->session()->pull('instagram_oauth_state', '');
        if ($expectedState === '' || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return redirect()->route('app.settings.integrations.instagram', $this->workspaceRouteParams($workspaceId))
                ->withErrors(['instagram' => 'Instagram connection expired or was already used. Please start Connect Instagram again.']);
        }

        $request->session()->forget('instagram_oauth_workspace_id');
        $ownerUserId = (int) $request->session()->pull('instagram_oauth_owner_user_id', $request->user()->id);
        $workspace = $this->workspace($request, $workspaceId);

        try {
            $shortToken = $client->exchangeCode((string) $request->query('code'));
            $longToken = $client->longLivedToken((string) data_get($shortToken, 'access_token'));
            $accessToken = (string) data_get($longToken, 'access_token', data_get($shortToken, 'access_token'));
            $accounts = $client->instagramAccounts($accessToken);
        } catch (RequestException|InvalidArgumentException $exception) {
            return redirect()->route('app.settings.integrations.instagram', ['workspace_id' => $workspace->id])
                ->withErrors(['instagram' => 'Instagram connected, but account access failed. Check Meta app permissions and reconnect the account.']);
        }

        $instagram = Arr::first($accounts);
        if (! $instagram) {
            return redirect()->route('app.settings.integrations.instagram', ['workspace_id' => $workspace->id])
                ->withErrors(['instagram' => self::PERSONAL_ACCOUNT_MESSAGE]);
        }

        $accountType = strtolower((string) data_get($instagram, 'account_type', ''));
        if (! in_array($accountType, self::PROFESSIONAL_TYPES, true)) {
            return redirect()->route('app.settings.integrations.instagram', ['workspace_id' => $workspace->id])
                ->withErrors(['instagram' => self::PERSONAL_ACCOUNT_MESSAGE]);
        }

        $channel = DistributionChannel::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Instagram'],
            [
                'organization_id' => $workspace->organization_id,
                'type' => DistributionChannelType::INSTAGRAM->value,
                'provider' => SocialPlatform::INSTAGRAM->value,
                'status' => DistributionChannel::STATUS_ACTIVE,
                'capabilities' => ['single_image_post', 'scheduled_publish'],
                'planning_rules' => ['requires_approval' => true, 'requires_media' => true],
                'metadata' => ['professional_accounts_only' => true],
            ],
        );

        $account = SocialAccount::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'provider' => SocialPlatform::INSTAGRAM->value,
                'platform_account_id' => (string) $instagram['id'],
            ],
            [
                'organization_id' => $workspace->organization_id,
                'user_id' => $ownerUserId,
                'distribution_channel_id' => $channel->id,
                'platform' => SocialPlatform::INSTAGRAM->value,
                'account_type' => $accountType,
                'display_name' => $instagram['username'] ?: ($instagram['name'] ?: 'Instagram account'),
                'status' => SocialAccountStatus::ACTIVE->value,
                'access_token' => $accessToken,
                'refresh_token' => null,
                'expires_at' => data_get($longToken, 'expires_in') ? now()->addSeconds((int) data_get($longToken, 'expires_in')) : null,
                'scopes' => config('services.meta.scopes', []),
                'oauth' => [
                    'provider' => 'meta',
                    'scope' => config('services.meta.scopes', []),
                    'identity_type' => 'instagram_'.$accountType,
                    'page_id' => data_get($instagram, 'page_id'),
                ],
                'profile' => [
                    'username' => data_get($instagram, 'username'),
                    'name' => data_get($instagram, 'name'),
                    'account_type' => $accountType,
                    'profile_image_url' => data_get($instagram, 'profile_picture_url'),
                    'labels' => [str($accountType)->title()->toString()],
                    'raw' => data_get($instagram, 'raw'),
                ],
                'publishing_rules' => [
                    'approval_required' => true,
                    'approval_policy' => 'required',
                    'permissions' => ['draft', 'schedule', 'publish'],
                    'requires_media' => true,
                ],
                'rate_limit_policy' => [
                    'bucket' => 'publish',
                    'retry_strategy' => 'exponential_backoff',
                ],
                'connected_at' => now(),
                'last_verified_at' => now(),
                'last_error' => null,
            ],
        );

        $audit->record($account, 'account.instagram_connected', null, $account->attributesToArray());

        return redirect()->route('app.settings.integrations.instagram', ['workspace_id' => $workspace->id])
            ->with('status', 'Instagram connected.');
    }

    public function disconnect(Request $request, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManageInstagram($request), 403);
        $workspace = $this->workspace($request);

        $account = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', SocialPlatform::INSTAGRAM->value)
            ->when($request->input('account_id'), fn ($query, $id) => $query->where('id', $id))
            ->latest('connected_at')
            ->firstOrFail();

        $before = $account->attributesToArray();
        $account->forceFill([
            'status' => SocialAccountStatus::REVOKED->value,
            'access_token' => null,
            'refresh_token' => null,
            'last_error' => null,
        ])->save();

        $audit->record($account, 'account.instagram_disconnected', $before, $account->attributesToArray());

        return back()->with('status', 'Instagram disconnected.');
    }

    private function workspace(Request $request, ?string $workspaceId = null): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($workspaceId ?: $request->query('workspace_id'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }

    /**
     * @return array<string,string>
     */
    private function workspaceRouteParams(string $workspaceId): array
    {
        return $workspaceId !== '' ? ['workspace_id' => $workspaceId] : [];
    }

    private function canManageInstagram(Request $request): bool
    {
        return in_array((string) $request->user()->role, ['owner', 'admin', 'superadmin'], true);
    }
}
