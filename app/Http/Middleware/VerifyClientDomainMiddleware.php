<?php

namespace App\Http\Middleware;

use App\Support\Connectors\ConnectorHeaders;
use Closure;
use Illuminate\Http\Request;

class VerifyClientDomainMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $integrationAuthMode = (string) $request->attributes->get('integration_auth_mode');
        if ($integrationAuthMode === 'api_key') {
            return $next($request);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client not resolved'], 401);
        }

        $siteHeader = ConnectorHeaders::site($request);
        if ($siteHeader === '') {
            return response()->json(['error' => 'Missing X-Argusly-Site'], 403);
        }

        $host = $this->extractHost($siteHeader);
        if ($host === '') {
            return response()->json(['error' => 'Invalid X-Argusly-Site'], 403);
        }

        $host = strtolower($host);

        // Allowed domains from DB
        $allowed = $clientSite->allowed_domains ?: [];
        if (! is_array($allowed)) {
            $allowed = [];
        }

        // Backward compatible safe default:
        // Always allow the host derived from clientSite->site_url, even if allowed_domains is empty.
        // This prevents local/dev setups from failing with 403 on ACK.
        $siteUrlHost = '';
        if (! empty($clientSite->site_url)) {
            $siteUrlHost = strtolower($this->extractHost((string) $clientSite->site_url));
        }

        // Normalize allowed list
        $normalizedAllowed = [];
        foreach ($allowed as $d) {
            $d = strtolower(trim((string) $d));
            if ($d !== '') {
                $normalizedAllowed[] = $d;
            }
        }

        // Inject site_url host as implicit allowed domain
        if ($siteUrlHost !== '' && ! in_array($siteUrlHost, $normalizedAllowed, true)) {
            $normalizedAllowed[] = $siteUrlHost;
        }

        // If still empty, fail explicitly (production safety)
        if (count($normalizedAllowed) === 0) {
            return response()->json([
                'error' => 'No allowed domains configured',
                'hint' => 'Set client_sites.allowed_domains or ensure client_sites.site_url is present',
            ], 403);
        }

        $ok = in_array($host, $normalizedAllowed, true);

        if (! $ok) {
            return response()->json([
                'error' => 'Domain not allowed',
                'host' => $host,
                'header_raw' => $siteHeader,
                'allowed' => $normalizedAllowed,
            ], 403);
        }

        $request->attributes->set('clientSiteHost', $host);

        return $next($request);
    }

    private function extractHost(string $value): string
    {
        $value = trim($value);

        // Accept raw host or full url
        if (str_contains($value, '://')) {
            $parsed = parse_url($value);

            return $parsed['host'] ?? '';
        }

        // Strip path if present
        $value = preg_split('/[\/\s]/', $value)[0] ?? '';

        return $value;
    }
}
