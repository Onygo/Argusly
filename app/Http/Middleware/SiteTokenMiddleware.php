<?php

namespace App\Http\Middleware;

use App\Models\SiteToken;
use App\Support\SiteUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SiteTokenMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $auth = (string) $request->header('Authorization');
        if (!str_starts_with($auth, 'Bearer ')) {
            return response()->json(['error' => 'Missing bearer token'], 401);
        }

        $token = trim(substr($auth, 7));
        if ($token === '') {
            return response()->json(['error' => 'Missing bearer token'], 401);
        }

        $hash = hash('sha256', $token);

        $siteToken = SiteToken::with('clientSite.workspace.organization', 'workspace.organization')
            ->where('token_hash', $hash)
            ->where('revoked', false)
            ->whereNull('revoked_at')
            ->first();

        if (! $siteToken) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        $clientSite = $siteToken->clientSite;
        $workspace = $siteToken->workspace ?: $clientSite?->workspace;
        $organization = $workspace?->organization;

        if (! $clientSite && $workspace) {
            $claimedHost = $this->resolveClaimedHost($request);
            if ($claimedHost !== '') {
                $clientSite = $workspace->clientSites()
                    ->where(function ($query) use ($claimedHost) {
                        $query->where('base_url', 'like', '%://' . $claimedHost)
                            ->orWhere('site_url', 'like', '%://' . $claimedHost);
                    })
                    ->first();
            }
        }

        if (
            ! $workspace ||
            ! $organization ||
            ($clientSite && ! $clientSite->is_active) ||
            ($clientSite && $clientSite->status === 'disabled') ||
            ! $organization->isActive()
        ) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        if ($clientSite && ! $this->claimsMatchSite($request, $clientSite)) {
            return response()->json(['error' => 'Token site scope mismatch'], 403);
        }

        if (! $this->passesReplayProtection($request, $siteToken)) {
            return response()->json(['error' => 'Invalid timestamp or nonce'], 401);
        }

        $siteToken->last_used_at = Carbon::now();
        $siteToken->last_ip = (string) $request->ip();
        $siteToken->save();

        if ($clientSite) {
            $clientSite->last_seen_at = now();
            if ($clientSite->status === 'pending') {
                $clientSite->status = 'connected';
            }
            $clientSite->save();
        }

        $request->attributes->set('siteToken', $siteToken);
        $request->attributes->set('clientSite', $clientSite);
        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }

    private function resolveClaimedHost(Request $request): string
    {
        $claimed = trim((string) ($request->input('site_url')
            ?: $request->input('client.site_url')
            ?: $request->header('X-PublishLayer-Site', '')));

        return SiteUrl::hostFromUrl($claimed);
    }

    private function claimsMatchSite(Request $request, \App\Models\ClientSite $site): bool
    {
        $claimedHost = $this->resolveClaimedHost($request);
        if ($claimedHost === '') {
            return true;
        }

        $siteHost = SiteUrl::hostFromUrl((string) ($site->base_url ?: $site->site_url));

        if ($siteHost === $claimedHost) {
            return true;
        }

        $allowed = is_array($site->allowed_domains) ? $site->allowed_domains : [];
        foreach ($allowed as $domain) {
            if (strtolower(trim((string) $domain)) === $claimedHost) {
                return true;
            }
        }

        return false;
    }

    private function passesReplayProtection(Request $request, SiteToken $siteToken): bool
    {
        $timestamp = trim((string) $request->header('X-PL-Timestamp', ''));
        $nonce = trim((string) $request->header('X-PL-Nonce', ''));
        $strict = (bool) config('publishlayer.wp_connector.require_timestamp_nonce', false);

        if (! $strict && $timestamp === '' && $nonce === '') {
            return true;
        }

        if ($timestamp === '' || $nonce === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $maxAge = (int) config('publishlayer.wp_connector.timestamp_ttl_seconds', 300);
        if ($maxAge <= 0) {
            $maxAge = 300;
        }

        $age = abs(now()->timestamp - (int) $timestamp);
        if ($age > $maxAge) {
            return false;
        }

        try {
            DB::table('site_token_nonces')->insert([
                'site_token_id' => $siteToken->id,
                'nonce' => $nonce,
                'used_at' => now(),
                'expires_at' => now()->addSeconds($maxAge),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            return false;
        }

        DB::table('site_token_nonces')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        return true;
    }
}
