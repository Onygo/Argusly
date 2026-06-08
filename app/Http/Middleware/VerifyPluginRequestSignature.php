<?php

namespace App\Http\Middleware;

use App\Services\PluginUpdates\LicenseKeyService;
use App\Support\Connectors\ConnectorHeaders;
use Closure;
use Illuminate\Http\Request;

class VerifyPluginRequestSignature
{
    public function __construct(
        private readonly LicenseKeyService $licenseKeyService
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $timestamp = ConnectorHeaders::timestamp($request);
        $signature = ConnectorHeaders::value($request, ConnectorHeaders::SIGNATURE);

        if ($timestamp === '' || $signature === '') {
            return response()->json(['error' => 'Missing signature headers'], 401);
        }

        if (! ctype_digit($timestamp)) {
            return response()->json(['error' => 'Invalid signature timestamp'], 401);
        }

        $age = abs(now()->timestamp - (int) $timestamp);
        $maxAge = (int) config('argusly.plugin_updates.signature_ttl_seconds', 300);
        if ($age > $maxAge) {
            return response()->json(['error' => 'Expired signature timestamp'], 401);
        }

        $licenseKey = (string) $request->input('license_key', '');
        $domain = $this->licenseKeyService->normalizeDomain(
            domain: $request->input('domain'),
            siteUrl: $request->input('site_url')
        );

        if ($licenseKey === '' || $domain === '') {
            return response()->json(['error' => 'Invalid signature context'], 401);
        }

        $license = $this->licenseKeyService->findByPlainKey($licenseKey);
        if (! $license) {
            return response()->json(['error' => 'Invalid signature context'], 401);
        }

        $secret = $this->licenseKeyService->deriveClientSecret($license, $domain);
        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $request->attributes->set('pluginLicense', $license);
        $request->attributes->set('pluginDomain', $domain);

        return $next($request);
    }
}
