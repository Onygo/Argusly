<?php

namespace App\Services;

use App\Models\ClientSite;
use App\Models\WorkspaceDomain;
use App\Support\PublicSiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PublicSiteContextResolver
{
    public function resolve(Request $request): PublicSiteContext
    {
        $host = strtolower(trim((string) $request->getHost()));
        $scheme = strtolower(trim((string) $request->getScheme())) ?: 'https';

        if ($host === '') {
            return PublicSiteContext::fallback(scheme: $scheme);
        }

        $baseDomain = strtolower(trim((string) config('domains.base', 'publishlayer.local')));
        if ($host === $baseDomain) {
            return $this->context(
                host: $host,
                scheme: $scheme,
                type: 'marketing',
                meta: ['base_domain' => $baseDomain]
            );
        }

        $workspaceDomain = $this->resolveWorkspaceDomain($host);
        if ($workspaceDomain) {
            return $this->context(
                host: $host,
                scheme: $scheme,
                type: 'workspace_domain',
                workspaceId: (string) $workspaceDomain->workspace_id,
                meta: ['workspace_domain_id' => (int) $workspaceDomain->id]
            );
        }

        $clientSite = $this->resolveClientSite($host);
        if ($clientSite) {
            return $this->context(
                host: $host,
                scheme: $scheme,
                type: 'client_site_domain',
                workspaceId: (string) $clientSite->workspace_id,
                clientSiteId: (string) $clientSite->id,
                meta: [
                    'site_url' => (string) ($clientSite->site_url ?? ''),
                    'base_url' => (string) ($clientSite->base_url ?? ''),
                ]
            );
        }

        return $this->context(host: $host, scheme: $scheme, type: 'external_host');
    }

    private function context(
        string $host,
        string $scheme,
        string $type,
        ?string $workspaceId = null,
        ?string $clientSiteId = null,
        array $meta = [],
    ): PublicSiteContext {
        return new PublicSiteContext(
            host: $host,
            scheme: $scheme,
            rootUrl: $scheme . '://' . $host,
            scopeKey: $host,
            type: $type,
            workspaceId: $workspaceId !== '' ? $workspaceId : null,
            clientSiteId: $clientSiteId !== '' ? $clientSiteId : null,
            meta: $meta,
        );
    }

    private function resolveWorkspaceDomain(string $host): ?WorkspaceDomain
    {
        if (! Schema::hasTable('workspace_domains')) {
            return null;
        }

        return WorkspaceDomain::query()
            ->select(['id', 'workspace_id', 'domain', 'verified_at'])
            ->whereNotNull('verified_at')
            ->whereRaw('LOWER(domain) = ?', [$host])
            ->first();
    }

    private function resolveClientSite(string $host): ?ClientSite
    {
        if (! Schema::hasTable('client_sites')) {
            return null;
        }

        return ClientSite::query()
            ->select(['id', 'workspace_id', 'site_url', 'base_url', 'allowed_domains', 'is_active'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (ClientSite $site): bool => $this->clientSiteMatchesHost($site, $host));
    }

    private function clientSiteMatchesHost(ClientSite $site, string $host): bool
    {
        foreach ($this->hostsForClientSite($site) as $candidate) {
            if ($candidate === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function hostsForClientSite(ClientSite $site): array
    {
        $hosts = [];

        foreach ([(string) ($site->site_url ?? ''), (string) ($site->base_url ?? '')] as $url) {
            $parsedHost = strtolower(trim((string) parse_url($url, PHP_URL_HOST)));
            if ($parsedHost !== '') {
                $hosts[] = $parsedHost;
            }
        }

        foreach ((array) ($site->allowed_domains ?? []) as $allowedDomain) {
            if (! is_string($allowedDomain)) {
                continue;
            }

            $normalized = strtolower(trim($allowedDomain));
            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, '://')) {
                $normalized = strtolower(trim((string) parse_url($normalized, PHP_URL_HOST)));
            }

            if ($normalized !== '') {
                $hosts[] = $normalized;
            }
        }

        return array_values(array_unique($hosts));
    }
}
