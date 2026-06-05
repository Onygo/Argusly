<?php

namespace App\Http\Middleware;

use App\Models\AnalyticsSite;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAnalyticsOriginAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('analytics.enabled', true)) {
            return response()->json(['error' => 'Analytics disabled'], 404);
        }

        $siteKey = $this->extractSiteKey($request);
        if ($siteKey === '') {
            return $next($request);
        }

        $site = AnalyticsSite::query()
            ->with('clientSite:id,site_url')
            ->where('public_key', $siteKey)
            ->first();

        if (! $site) {
            return $next($request);
        }

        $allowedDomains = $this->normalizeAllowedDomains($site);
        $originHost = $this->extractHost((string) $request->header('Origin', ''));
        $refererHost = $this->extractHost((string) $request->header('Referer', ''));

        if ($originHost !== '' && ! $this->isHostAllowed($originHost, $allowedDomains)) {
            return $this->forbiddenResponse();
        }

        if ($originHost === '' && $refererHost !== '' && ! $this->isHostAllowed($refererHost, $allowedDomains)) {
            return $this->forbiddenResponse();
        }

        $request->attributes->set('analytics.site', $site);
        $request->attributes->set('analytics.allowed_domains', $allowedDomains);

        return $next($request);
    }

    private function extractSiteKey(Request $request): string
    {
        $siteKey = trim((string) $request->input('site_key', $request->input('site', '')));
        if ($siteKey !== '') {
            return $siteKey;
        }

        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return '';
        }

        return trim((string) ($decoded['site_key'] ?? $decoded['site'] ?? ''));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeAllowedDomains(AnalyticsSite $site): array
    {
        $domains = $site->allowed_domains;
        if (! is_array($domains)) {
            $domains = [];
        }

        $siteHost = $this->extractHost((string) ($site->clientSite?->site_url ?? ''));
        if ($siteHost !== '') {
            $domains[] = $siteHost;
        }

        return collect($domains)
            ->map(fn ($domain) => $this->extractHost((string) $domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isHostAllowed(string $host, array $allowedDomains): bool
    {
        foreach ($allowedDomains as $allowedDomain) {
            if ($host === $allowedDomain) {
                return true;
            }

            if (str_ends_with($host, '.' . $allowedDomain)) {
                return true;
            }
        }

        return false;
    }

    private function extractHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'https://' . ltrim($value, '/');
        }

        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) ? strtolower(trim($host)) : '';
    }

    private function forbiddenResponse(): JsonResponse
    {
        return response()->json(['error' => 'Origin not allowed'], 403);
    }
}
