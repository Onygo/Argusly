<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Models\ContentPublication;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContentRelationService
{
    /**
     * @param array<int,string> $excludeContentIds
     * @return Collection<int,Content>
     */
    public function relatedContents(
        Content $source,
        ?string $locale = null,
        bool $sameSite = true,
        bool $publishedOnly = true,
        array $excludeContentIds = [],
        ?int $limit = null,
    ): Collection {
        $source->loadMissing('seriesArticle:id,series_id,content_id,article_number,is_pillar');

        $resolvedLocale = $locale !== null ? Str::lower(trim($locale)) : null;
        $normalizedExcludeIds = collect($excludeContentIds)
            ->map(fn (string $id): string => trim($id))
            ->filter()
            ->push((string) $source->id)
            ->unique()
            ->values()
            ->all();

        $query = Content::query()
            ->with([
                'clientSite:id,base_url,site_url,allowed_domains',
                'seriesArticle:id,series_id,content_id,article_number,is_pillar',
                'currentRevision',
                'currentVersion',
                'publications' => fn ($publicationQuery) => $this->publishedPublicationQuery($publicationQuery, $sameSite ? (string) $source->client_site_id : null, $resolvedLocale),
            ])
            ->whereNotIn('id', $normalizedExcludeIds);

        if ($sameSite) {
            $query->where('client_site_id', (string) $source->client_site_id);
        } else {
            $query->where('workspace_id', (string) $source->workspace_id);
        }

        if ($resolvedLocale !== null && $resolvedLocale !== '') {
            $query->where('language', $resolvedLocale);
        }

        if ($publishedOnly) {
            $query->where('status', 'published')
                ->whereHas('publications', fn ($publicationQuery) => $this->publishedPublicationQuery($publicationQuery, $sameSite ? (string) $source->client_site_id : null, $resolvedLocale));
        }

        $query->orderByDesc('updated_at')
            ->orderBy('title');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function publishedPublicationQuery($query, ?string $siteId, ?string $locale)
    {
        return $query
            ->when($siteId !== null && $siteId !== '', fn ($builder) => $builder->where('client_site_id', $siteId))
            ->when($locale !== null && $locale !== '', fn ($builder) => $builder->where('locale', $locale))
            ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
            ->whereIn('remote_status', $this->publishedRemoteStatuses())
            ->whereNotNull('remote_url')
            ->where('remote_url', '!=', '')
            ->orderByDesc('last_verified_at')
            ->orderByDesc('last_delivered_at');
    }

    /**
     * @return array<int,string>
     */
    private function publishedRemoteStatuses(): array
    {
        return [ContentPublication::REMOTE_PUBLISHED, 'publish', 'live'];
    }

    public function relationship(Content $source, Content $candidate): string
    {
        $source->loadMissing('seriesArticle:id,series_id,content_id,article_number,is_pillar');
        $candidate->loadMissing('seriesArticle:id,series_id,content_id,article_number,is_pillar');

        $preferSameChain = (bool) config('internal_linking.prefer_same_chain', true);
        if (! $preferSameChain || ! $source->series_id || (string) $source->series_id !== (string) $candidate->series_id) {
            return 'topic_related';
        }

        $sourceIsPillar = (bool) ($source->seriesArticle?->is_pillar ?? false);
        $candidateIsPillar = (bool) ($candidate->seriesArticle?->is_pillar ?? false);

        if (! $sourceIsPillar && $candidateIsPillar) {
            return 'same_chain_pillar';
        }

        if ($sourceIsPillar && ! $candidateIsPillar) {
            return 'same_chain_supporting';
        }

        return 'same_chain_related';
    }

    public function chainOrder(Content $content): ?int
    {
        $content->loadMissing('seriesArticle:id,series_id,content_id,article_number,is_pillar');

        return $content->seriesArticle?->article_number !== null
            ? (int) $content->seriesArticle->article_number
            : null;
    }

    public function pillarContent(Content $content): ?Content
    {
        if (! $content->series_id) {
            return null;
        }

        $content->loadMissing('series.contents.seriesArticle:id,series_id,content_id,article_number,is_pillar');

        return $content->series?->contents
            ->first(fn (Content $candidate): bool => (bool) ($candidate->seriesArticle?->is_pillar ?? false));
    }

    public function newerChainArticleCount(Content $content, ?DateTimeInterface $reference = null): int
    {
        if (! $content->series) {
            $content->loadMissing('series.contents.currentVersion', 'series.contents.currentRevision');
        } else {
            $content->series->loadMissing('contents.currentVersion', 'contents.currentRevision');
        }

        if (! $content->series) {
            return 0;
        }

        $reference ??= $this->latestReferenceAt($content);
        if (! $reference) {
            return 0;
        }

        return $content->series->contents
            ->reject(fn (Content $candidate): bool => (string) $candidate->id === (string) $content->id)
            ->filter(function (Content $candidate) use ($reference): bool {
                $candidateLatest = $this->latestReferenceAt($candidate);

                return $candidateLatest !== null && $candidateLatest > $reference;
            })
            ->count();
    }

    public function latestReferenceAt(Content $content): ?\Illuminate\Support\Carbon
    {
        $content->loadMissing('currentRevision', 'currentVersion');

        return collect([
            $content->currentVersion?->updated_at,
            $content->currentVersion?->created_at,
            $content->currentRevision?->updated_at,
            $content->currentRevision?->created_at,
            $content->updated_at,
            $content->created_at,
        ])->filter()->sortDesc()->first();
    }
}
