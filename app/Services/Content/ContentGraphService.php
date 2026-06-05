<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Models\ClientSite;
use App\Models\ContentPublication;
use App\Support\SiteUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentGraphService
{
    public function __construct(
        private readonly ContentRelationService $contentRelationService,
    ) {
    }

    /**
     * @param array<int,string> $excludeContentIds
     * @param array<int,string> $excludeUrls
     * @return Collection<int,array{
     *   content:Content,
     *   target_url:string,
     *   relationship:string
     * }>
     */
    public function linkableCandidatesFor(
        Content $source,
        string $locale,
        array $excludeContentIds = [],
        array $excludeUrls = [],
        ?int $limit = null,
    ): Collection {
        $source->loadMissing([
            'clientSite:id,base_url,site_url,allowed_domains',
            'seriesArticle:id,series_id,content_id,article_number,is_pillar',
        ]);

        $limit ??= max(8, (int) config('internal_linking.candidate_limit', 12));
        $resolvedLocale = Str::lower(trim($locale));
        $normalizedExcludeUrls = collect($excludeUrls)
            ->map(fn (string $url): string => $this->normalizeUrl($url))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $normalizedExcludeIds = collect($excludeContentIds)
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->push((string) $source->id)
            ->unique()
            ->values()
            ->all();

        return $this->contentRelationService
            ->relatedContents(
                $source,
                locale: $resolvedLocale,
                sameSite: true,
                publishedOnly: true,
                excludeContentIds: $normalizedExcludeIds,
                limit: $limit * 2,
            )
            ->map(function (Content $candidate) use ($source): ?array {
                $targetUrl = $this->resolveTargetUrl($candidate);
                if ($targetUrl === '') {
                    return null;
                }

                return [
                    'content' => $candidate,
                    'target_url' => $targetUrl,
                    'relationship' => $this->contentRelationService->relationship($source, $candidate),
                ];
            })
            ->filter(function (?array $candidate) use ($normalizedExcludeUrls): bool {
                if (! is_array($candidate)) {
                    return false;
                }

                return ! in_array($this->normalizeUrl((string) $candidate['target_url']), $normalizedExcludeUrls, true);
            })
            ->values();
    }

    public function resolveTargetUrl(Content $content): string
    {
        $content->loadMissing([
            'clientSite:id,base_url,site_url,allowed_domains',
            'publications' => fn ($publicationQuery) => $publicationQuery
                ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
                ->whereIn('remote_status', $this->publishedRemoteStatuses())
                ->whereNotNull('remote_url')
                ->where('remote_url', '!=', '')
                ->orderByDesc('last_verified_at')
                ->orderByDesc('last_delivered_at'),
        ]);

        $publication = $content->publications
            ->first(fn (ContentPublication $publication): bool => $this->isPublishedPublication($publication));

        return $this->resolveSiteScopedUrl((string) ($publication?->remote_url ?? ''), $content->clientSite);
    }

    private function isPublishedPublication(ContentPublication $publication): bool
    {
        return (string) ($publication->delivery_status ?? '') === ContentPublication::STATUS_DELIVERED
            && in_array((string) ($publication->remote_status ?? ''), $this->publishedRemoteStatuses(), true)
            && trim((string) ($publication->remote_url ?? '')) !== '';
    }

    /**
     * @return array<int,string>
     */
    private function publishedRemoteStatuses(): array
    {
        return [ContentPublication::REMOTE_PUBLISHED, 'publish', 'live'];
    }

    private function resolveSiteScopedUrl(string $url, ?ClientSite $site): string
    {
        $candidate = trim($url);
        if ($candidate === '') {
            return '';
        }

        $base = rtrim((string) ($site?->site_url ?: $site?->base_url ?: ''), '/');
        if (str_starts_with($candidate, '/')) {
            return $base !== ''
                ? $base.'/'.ltrim($candidate, '/')
                : '';
        }

        if (! str_contains($candidate, '://')) {
            return '';
        }

        $host = SiteUrl::hostFromUrl($candidate);
        if ($host === '' || ! $this->hostMatchesSite($host, $site)) {
            return '';
        }

        return $candidate;
    }

    private function hostMatchesSite(string $host, ?ClientSite $site): bool
    {
        if (! $site) {
            return false;
        }

        $normalizedHost = Str::lower(trim($host));
        $siteHost = SiteUrl::hostFromUrl((string) ($site->site_url ?: $site->base_url ?: ''));
        if ($siteHost !== '' && $siteHost === $normalizedHost) {
            return true;
        }

        return collect((array) ($site->allowed_domains ?? []))
            ->map(fn (mixed $domain): string => Str::lower(trim((string) $domain)))
            ->filter()
            ->contains($normalizedHost);
    }

    public function normalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        $normalized = Str::lower($trimmed);

        return rtrim($normalized, '/');
    }
}
