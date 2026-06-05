<?php

namespace App\Http\Controllers\App\Settings;

use App\Enums\DistributionChannelType;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Models\DistributionChannel;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\LinkedIn\LinkedInClient;
use App\Services\SocialDistribution\SocialDistributionAuditLogger;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LinkedInIntegrationController extends Controller
{
    public function show(Request $request): View
    {
        $workspace = $this->workspace($request);
        $accounts = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', SocialPlatform::LINKEDIN->value)
            ->with('user')
            ->latest('connected_at')
            ->latest()
            ->get();

        return view('app.settings.integrations.linkedin', [
            'workspace' => $workspace,
            'accounts' => $accounts,
            'account' => $accounts->first(),
            'linkedinEnabled' => (bool) config('services.linkedin.enabled'),
            'publishingEnabled' => (bool) config('services.linkedin.publishing_enabled'),
            'canManageLinkedIn' => $this->canManageLinkedIn($request),
            'configuredRedirectUri' => (string) config('services.linkedin.redirect_uri'),
        ]);
    }

    public function connect(Request $request, LinkedInClient $client): RedirectResponse
    {
        if (! $this->canManageLinkedIn($request)) {
            return redirect()->route('app.settings.integrations.linkedin', ['workspace_id' => $request->query('workspace_id')])
                ->withErrors(['linkedin' => 'Only workspace owners and admins can connect a LinkedIn account.']);
        }

        if (! (bool) config('services.linkedin.enabled')) {
            return redirect()->route('app.settings.integrations.linkedin', ['workspace_id' => $request->query('workspace_id')])
                ->withErrors(['linkedin' => 'LinkedIn OAuth is not enabled yet. Add the LinkedIn app credentials and enable the integration first.']);
        }

        $state = Str::random(48);
        $workspace = $this->workspace($request);
        $request->session()->put('linkedin_oauth_state', $state);
        $request->session()->put('linkedin_oauth_workspace_id', (string) $workspace->id);
        $request->session()->put('linkedin_oauth_owner_user_id', (int) $request->user()->id);

        return redirect()->away($client->authorizationUrl($state));
    }

    public function callback(Request $request, LinkedInClient $client, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManageLinkedIn($request), 403);

        $workspaceId = (string) $request->session()->get('linkedin_oauth_workspace_id', $request->query('workspace_id', ''));

        if ($request->filled('error')) {
            return redirect()->route('app.settings.integrations.linkedin', $this->workspaceRouteParams($workspaceId))
                ->withErrors(['linkedin' => 'LinkedIn access was not granted: '.$request->query('error_description', $request->query('error'))]);
        }

        $expectedState = (string) $request->session()->pull('linkedin_oauth_state', '');
        if ($expectedState === '' || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return redirect()->route('app.settings.integrations.linkedin', $this->workspaceRouteParams($workspaceId))
                ->withErrors(['linkedin' => 'LinkedIn connection expired or was already used. Please start Connect LinkedIn again.']);
        }

        $request->session()->forget('linkedin_oauth_workspace_id');
        $ownerUserId = (int) $request->session()->pull('linkedin_oauth_owner_user_id', $request->user()->id);
        $workspace = $this->workspace($request, $workspaceId);
        try {
            $token = $client->exchangeCode((string) $request->query('code'));
            $member = $client->member((string) $token['access_token']);
        } catch (RequestException $exception) {
            return redirect()->route('app.settings.integrations.linkedin', ['workspace_id' => $workspace->id])
                ->withErrors(['linkedin' => 'LinkedIn connected, but profile access failed. Make sure the LinkedIn app has OpenID Connect enabled and reconnect the account.']);
        }
        $memberUrn = str_starts_with($member['id'], 'urn:li:person:')
            ? $member['id']
            : 'urn:li:person:'.$member['id'];

        $channel = DistributionChannel::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'LinkedIn'],
            [
                'organization_id' => $workspace->organization_id,
                'type' => DistributionChannelType::LINKEDIN->value,
                'provider' => SocialPlatform::LINKEDIN->value,
                'status' => DistributionChannel::STATUS_ACTIVE,
                'capabilities' => ['text_post', 'article_share', 'scheduled_publish'],
                'planning_rules' => ['requires_approval' => true],
                'metadata' => ['personal_profile_mvp' => true],
            ],
        );

        $account = SocialAccount::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'provider' => SocialPlatform::LINKEDIN->value,
                'provider_member_urn' => $memberUrn,
            ],
            [
                'organization_id' => $workspace->organization_id,
                'user_id' => $ownerUserId,
                'distribution_channel_id' => $channel->id,
                'platform' => SocialPlatform::LINKEDIN->value,
                'account_type' => 'person',
                'display_name' => $member['name'] ?: 'LinkedIn member',
                'platform_account_id' => $memberUrn,
                'status' => SocialAccountStatus::ACTIVE->value,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
                'scopes' => config('services.linkedin.scopes', ['w_member_social']),
                'oauth' => [
                    'provider' => 'linkedin',
                    'scope' => 'w_member_social',
                    'identity_type' => 'person',
                ],
                'profile' => array_replace((array) ($member['raw'] ?? []), [
                    'labels' => ['Personal'],
                    'engagement_role' => 'primary_publisher',
                ]),
                'publishing_rules' => [
                    'approval_required' => true,
                    'approval_policy' => 'required',
                    'permissions' => ['draft', 'schedule', 'publish'],
                ],
                'rate_limit_policy' => [
                    'member_requests_per_day' => config('services.linkedin.member_daily_limit', 150),
                    'application_requests_per_day' => config('services.linkedin.application_daily_limit', 100000),
                ],
                'connected_at' => now(),
                'last_verified_at' => now(),
                'last_error' => null,
            ],
        );

        $audit->record($account, 'account.linkedin_connected', null, $account->attributesToArray());

        return redirect()->route('app.settings.integrations.linkedin', ['workspace_id' => $workspace->id])->with('status', 'LinkedIn connected.');
    }

    public function disconnect(Request $request, SocialDistributionAuditLogger $audit): RedirectResponse
    {
        abort_unless($this->canManageLinkedIn($request), 403);
        $workspace = $this->workspace($request);

        $account = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', SocialPlatform::LINKEDIN->value)
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

        $audit->record($account, 'account.linkedin_disconnected', $before, $account->attributesToArray());

        return back()->with('status', 'LinkedIn disconnected.');
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

    private function canManageLinkedIn(Request $request): bool
    {
        return in_array((string) $request->user()->role, ['owner', 'admin', 'superadmin'], true);
    }
}
