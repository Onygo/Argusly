<?php

namespace App\Services\Content;

use App\Enums\SupportedLanguage;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\WorkspaceDomain;
use App\Services\PublicBlog\MarketingBlogSourceScope;
use App\Services\Sitemap\SitemapCacheManager;
use App\Support\SiteUrl;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ContentCacheInvalidationService
{
    private const PUBLIC_BLOG_VERSION_PREFIX = 'argusly:public_blog';

    public function __construct(
        private readonly MarketingBlogSourceScope $blogScope,
        private readonly LocaleContentMapService $localeMap,
        private readonly SitemapCacheManager $sitemapCache,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function invalidateContent(Content $content, string $reason = 'content.changed'): array
    {
        $content->loadMissing('clientSite', 'translationSourceContent', 'localizedVariants');

        if ((string) $content->type !== 'article') {
            return $this->logSkipped($reason, [
                'content_id' => (string) $content->id,
                'skip_reason' => 'unsupported_type',
            ]);
        }

        $configuredScope = $this->configuredScope();
        if (! $this->contentMatchesConfiguredScope($content, $configuredScope)) {
            return $this->logSkipped($reason, [
                'content_id' => (string) $content->id,
                'site_id' => (string) ($content->client_site_id ?? ''),
                'workspace_id' => (string) ($content->workspace_id ?? ''),
                'skip_reason' => 'outside_public_scope',
            ]);
        }

        $scopeSegment = $this->scopeSegment($configuredScope);
        $locales = $this->affectedLocalesForContent($content);
        $hosts = $this->hostScopesForContent($content, $configuredScope);
        $invalidated = $this->invalidateScope($scopeSegment, $locales, $hosts);

        Log::debug('content_cache.invalidated', [
            'reason' => $reason,
            'content_id' => (string) $content->id,
            'publication_id' => null,
            'site_id' => (string) ($content->client_site_id ?? ''),
            'workspace_id' => (string) ($content->workspace_id ?? ''),
            'locales' => $locales,
            'scope' => $configuredScope,
            'scope_segment' => $scopeSegment,
            'hosts' => $hosts,
            'invalidated' => $invalidated,
        ]);

        return [
            'reason' => $reason,
            'content_id' => (string) $content->id,
            'publication_id' => null,
            'scope' => $configuredScope,
            'scope_segment' => $scopeSegment,
            'locales' => $locales,
            'hosts' => $hosts,
            'invalidated' => $invalidated,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function invalidatePublication(ContentPublication $publication, string $reason = 'publication.changed'): array
    {
        $publication->loadMissing('content.clientSite', 'clientSite');

        if ($publication->content instanceof Content) {
            $result = $this->invalidateContent($publication->content, $reason);
            $result['publication_id'] = (string) $publication->id;

            Log::debug('content_cache.publication_invalidated', [
                'reason' => $reason,
                'publication_id' => (string) $publication->id,
                'content_id' => (string) $publication->content->id,
                'site_id' => (string) ($publication->client_site_id ?? $publication->content->client_site_id ?? ''),
                'locale' => (string) ($publication->locale?->value ?? $publication->getRawOriginal('locale') ?? ''),
            ]);

            return $result;
        }

        if ($publication->clientSite instanceof ClientSite) {
            return $this->invalidateSite($publication->clientSite, $reason);
        }

        return $this->logSkipped($reason, [
            'publication_id' => (string) $publication->id,
            'skip_reason' => 'publication_without_scope',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function invalidateSite(ClientSite $site, string $reason = 'site.changed'): array
    {
        $configuredScope = $this->configuredScope();
        if (! $this->siteMatchesConfiguredScope($site, $configuredScope)) {
            return $this->logSkipped($reason, [
                'site_id' => (string) $site->id,
                'skip_reason' => 'outside_public_scope',
            ]);
        }

        $scopeSegment = $this->scopeSegment($configuredScope);
        $locales = $this->allPublicLocales();
        $hosts = $this->hostScopesForSite($site, $configuredScope);
        $invalidated = $this->invalidateScope($scopeSegment, $locales, $hosts);

        Log::debug('content_cache.site_invalidated', [
            'reason' => $reason,
            'site_id' => (string) $site->id,
            'workspace_id' => (string) ($site->workspace_id ?? ''),
            'locales' => $locales,
            'scope' => $configuredScope,
            'scope_segment' => $scopeSegment,
            'hosts' => $hosts,
            'invalidated' => $invalidated,
        ]);

        return [
            'reason' => $reason,
            'site_id' => (string) $site->id,
            'scope' => $configuredScope,
            'scope_segment' => $scopeSegment,
            'locales' => $locales,
            'hosts' => $hosts,
            'invalidated' => $invalidated,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function invalidatePublicContent(string $reason = 'public_content.changed'): array
    {
        $configuredScope = $this->configuredScope();
        if (! is_array($configuredScope)) {
            return $this->logSkipped($reason, [
                'skip_reason' => 'public_blog_scope_unconfigured',
            ]);
        }

        $scopeSegment = $this->scopeSegment($configuredScope);
        $locales = $this->allPublicLocales();
        $hosts = $this->hostScopesForConfiguredScope($configuredScope);
        $invalidated = $this->invalidateScope($scopeSegment, $locales, $hosts);

        Log::debug('content_cache.public_invalidated', [
            'reason' => $reason,
            'scope' => $configuredScope,
            'scope_segment' => $scopeSegment,
            'locales' => $locales,
            'hosts' => $hosts,
            'invalidated' => $invalidated,
        ]);

        return [
            'reason' => $reason,
            'scope' => $configuredScope,
            'scope_segment' => $scopeSegment,
            'locales' => $locales,
            'hosts' => $hosts,
            'invalidated' => $invalidated,
        ];
    }

    public static function publicBlogScopeVersionKey(string $scopeSegment): string
    {
        return self::PUBLIC_BLOG_VERSION_PREFIX . ':scope:' . $scopeSegment . ':version';
    }

    public static function publicBlogLocaleVersionKey(string $scopeSegment, string $locale): string
    {
        return self::PUBLIC_BLOG_VERSION_PREFIX . ':locale:' . $scopeSegment . ':' . Str::lower(trim($locale)) . ':version';
    }

    public static function publicBlogBaseTag(string $scopeSegment): string
    {
        return 'public_blog.scope_base.' . $scopeSegment;
    }

    public static function publicBlogLocaleTag(string $scopeSegment, string $locale): string
    {
        return 'public_blog.scope_locale.' . $scopeSegment . '.' . Str::lower(trim($locale));
    }

    /**
     * @param  array{mode:string,id:string}  $configuredScope
     * @return array<int,string>
     */
    private function hostScopesForContent(Content $content, array $configuredScope): array
    {
        $hosts = ['default'];

        $baseDomain = strtolower(trim((string) config('domains.base', '')));
        if ($baseDomain !== '') {
            $hosts[] = $baseDomain;
        }

        $appHost = strtolower(trim((string) parse_url((string) config('app.url', ''), PHP_URL_HOST)));
        if ($appHost !== '') {
            $hosts[] = $appHost;
        }

        if ($content->clientSite instanceof ClientSite) {
            $hosts = array_merge($hosts, $this->hostsForClientSite($content->clientSite));
        }

        $workspaceId = trim((string) $content->workspace_id);
        if ($workspaceId !== '') {
            $hosts = array_merge($hosts, $this->workspaceDomainHosts($workspaceId));

            if ($configuredScope['mode'] === 'workspace') {
                $hosts = array_merge($hosts, $this->activeClientSiteHostsForWorkspace($workspaceId));
            }
        }

        return collect($hosts)
            ->map(fn (string $host): string => strtolower(trim($host)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{mode:string,id:string}  $configuredScope
     * @return array<int,string>
     */
    private function hostScopesForSite(ClientSite $site, array $configuredScope): array
    {
        $hosts = array_merge(
            $this->baseHostScopes(),
            $this->hostsForClientSite($site),
            $this->workspaceDomainHosts((string) $site->workspace_id)
        );

        if ($configuredScope['mode'] === 'workspace') {
            $hosts = array_merge($hosts, $this->activeClientSiteHostsForWorkspace((string) $site->workspace_id));
        }

        return collect($hosts)->map(fn (string $host): string => strtolower(trim($host)))->filter()->unique()->values()->all();
    }

    /**
     * @param  array{mode:string,id:string}  $configuredScope
     * @return array<int,string>
     */
    private function hostScopesForConfiguredScope(array $configuredScope): array
    {
        $hosts = $this->baseHostScopes();

        if ($configuredScope['mode'] === 'workspace') {
            $hosts = array_merge(
                $hosts,
                $this->workspaceDomainHosts((string) $configuredScope['id']),
                $this->activeClientSiteHostsForWorkspace((string) $configuredScope['id'])
            );
        }

        if ($configuredScope['mode'] === 'site') {
            $site = ClientSite::query()
                ->select(['id', 'workspace_id', 'site_url', 'base_url', 'allowed_domains'])
                ->find((string) $configuredScope['id']);

            if ($site instanceof ClientSite) {
                $hosts = array_merge(
                    $hosts,
                    $this->hostsForClientSite($site),
                    $this->workspaceDomainHosts((string) $site->workspace_id)
                );
            }
        }

        return collect($hosts)->map(fn (string $host): string => strtolower(trim($host)))->filter()->unique()->values()->all();
    }

    /**
     * @param  array{mode:string,id:string}|null  $configuredScope
     */
    private function contentMatchesConfiguredScope(Content $content, ?array $configuredScope): bool
    {
        if (! is_array($configuredScope)) {
            return false;
        }

        return match ($configuredScope['mode']) {
            'site' => (string) $content->client_site_id === (string) $configuredScope['id'],
            'workspace' => (string) $content->workspace_id === (string) $configuredScope['id'],
            default => false,
        };
    }

    /**
     * @param  array{mode:string,id:string}|null  $configuredScope
     */
    private function siteMatchesConfiguredScope(ClientSite $site, ?array $configuredScope): bool
    {
        if (! is_array($configuredScope)) {
            return false;
        }

        return match ($configuredScope['mode']) {
            'site' => (string) $site->id === (string) $configuredScope['id'],
            'workspace' => (string) $site->workspace_id === (string) $configuredScope['id'],
            default => false,
        };
    }

    /**
     * @return array{mode:string,id:string}|null
     */
    private function configuredScope(): ?array
    {
        return $this->blogScope->resolve();
    }

    /**
     * @param  array{mode:string,id:string}  $configuredScope
     */
    private function scopeSegment(array $configuredScope): string
    {
        return $configuredScope['mode'] . '_' . md5((string) $configuredScope['id']);
    }

    /**
     * @return array<int,string>
     */
    private function affectedLocalesForContent(Content $content): array
    {
        $locales = $this->localeMap->family($content)
            ->map(fn (Content $variant): string => $variant->localeCode())
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($locales === []) {
            $locales[] = $content->localeCode();
        }

        return array_values(array_unique(array_filter($locales)));
    }

    /**
     * @return array<int,string>
     */
    private function allPublicLocales(): array
    {
        return collect(SupportedLanguage::values())
            ->map(fn (string $locale): string => strtolower(trim($locale)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function invalidateScope(string $scopeSegment, array $locales, array $hosts): array
    {
        $this->bumpVersion(self::publicBlogScopeVersionKey($scopeSegment));

        foreach ($locales as $locale) {
            $this->bumpVersion(self::publicBlogLocaleVersionKey($scopeSegment, $locale));
        }

        return [
            'public_blog' => [
                'scope_version_key' => self::publicBlogScopeVersionKey($scopeSegment),
                'locale_version_keys' => array_map(
                    fn (string $locale): string => self::publicBlogLocaleVersionKey($scopeSegment, $locale),
                    $locales
                ),
                'flushed_tags' => $this->flushPublicBlogTags($scopeSegment, $locales),
            ],
            'llms' => $this->forgetLlmsKeys($hosts, $locales),
            'sitemap' => $this->forgetSitemapScopes($hosts),
        ];
    }

    private function bumpVersion(string $key): void
    {
        $current = (int) Cache::get($key, 1);
        Cache::forever($key, max(1, $current + 1));
    }

    /**
     * @return array<int,string>
     */
    private function flushPublicBlogTags(string $scopeSegment, array $locales): array
    {
        if (! $this->supportsTags()) {
            return [];
        }

        $tags = [self::publicBlogBaseTag($scopeSegment)];

        foreach ($locales as $locale) {
            $tags[] = self::publicBlogLocaleTag($scopeSegment, $locale);
        }

        foreach ($tags as $tag) {
            Cache::tags([$tag])->flush();
        }

        return $tags;
    }

    /**
     * @return array<int,string>
     */
    private function forgetLlmsKeys(array $hosts, array $locales): array
    {
        $forgotten = [];

        foreach ($hosts as $scope) {
            foreach ($locales as $locale) {
                foreach (['summary', 'full'] as $variant) {
                    $key = sprintf('public_llms.%s.%s.%s', $variant, $locale, $scope);
                    Cache::forget($key);
                    $forgotten[] = $key;
                }
            }
        }

        return $forgotten;
    }

    /**
     * @return array<int,string>
     */
    private function forgetSitemapScopes(array $hosts): array
    {
        $forgotten = [];
        $locales = $this->allPublicLocales();

        foreach ($hosts as $scope) {
            $this->sitemapCache->forget($scope);
            $forgotten[] = $scope;

            foreach ($locales as $locale) {
                $localizedScope = $scope . ':' . $locale;
                $this->sitemapCache->forget($localizedScope);
                $forgotten[] = $localizedScope;
            }
        }

        return $forgotten;
    }

    /**
     * @return array<int,string>
     */
    private function activeClientSiteHostsForWorkspace(string $workspaceId): array
    {
        if ($workspaceId === '') {
            return [];
        }

        return ClientSite::query()
            ->select(['id', 'workspace_id', 'site_url', 'base_url', 'allowed_domains'])
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->get()
            ->flatMap(fn (ClientSite $site): array => $this->hostsForClientSite($site))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function workspaceDomainHosts(string $workspaceId): array
    {
        if ($workspaceId === '' || ! Schema::hasTable('workspace_domains')) {
            return [];
        }

        return WorkspaceDomain::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('verified_at')
            ->pluck('domain')
            ->map(fn ($domain): string => strtolower(trim((string) $domain)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function hostsForClientSite(ClientSite $site): array
    {
        return collect([
            SiteUrl::hostFromUrl((string) ($site->site_url ?? '')),
            SiteUrl::hostFromUrl((string) ($site->base_url ?? '')),
        ])
            ->merge((array) ($site->allowed_domains ?? []))
            ->map(fn (mixed $host): string => strtolower(trim((string) $host)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function supportsTags(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    /**
     * @return array<int,string>
     */
    private function baseHostScopes(): array
    {
        $hosts = ['default'];

        $baseDomain = strtolower(trim((string) config('domains.base', '')));
        if ($baseDomain !== '') {
            $hosts[] = $baseDomain;
        }

        $appHost = strtolower(trim((string) parse_url((string) config('app.url', ''), PHP_URL_HOST)));
        if ($appHost !== '') {
            $hosts[] = $appHost;
        }

        return $hosts;
    }

    /**
     * @return array<string,mixed>
     */
    private function logSkipped(string $reason, array $context): array
    {
        Log::debug('content_cache.skipped', array_merge(['reason' => $reason], $context));

        return array_merge(['reason' => $reason, 'invalidated' => []], $context);
    }
}
