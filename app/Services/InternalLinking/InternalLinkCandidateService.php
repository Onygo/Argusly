<?php

namespace App\Services\InternalLinking;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentSeriesArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InternalLinkCandidateService
{
    /**
     * @return Collection<int,array{
     *   content:Content,
     *   target_url:string,
     *   similarity_score:float,
     *   priority_score:float,
     *   relationship:string,
     *   anchor_options:array<int,string>
     * }>
     */
    public function candidatesFor(Content $source, ?string $sourceHtml = null, ?int $limit = null): Collection
    {
        $source->loadMissing(['clientSite', 'seriesArticle']);

        $limit ??= max(1, (int) config('internal_linking.candidate_limit', 12));
        $sourceHtml ??= $this->sourceHtml($source);
        $sourcePlainText = $this->plainText($sourceHtml);

        $query = Content::query()
            ->with([
                'clientSite:id,base_url,site_url',
                'seriesArticle:id,series_id,content_id,article_number,is_pillar',
                'publications' => fn ($publicationQuery) => $this->publishedPublicationQuery($publicationQuery, (string) $source->client_site_id, $source->localeCode()),
            ])
            ->where('workspace_id', (string) $source->workspace_id)
            ->where('client_site_id', (string) $source->client_site_id)
            ->where('id', '!=', (string) $source->id)
            ->where('language', $source->localeCode())
            ->where('status', 'published')
            ->whereHas('publications', fn ($publicationQuery) => $this->publishedPublicationQuery($publicationQuery, (string) $source->client_site_id, $source->localeCode()))
            ->orderBy('created_at')
            ->orderBy('title');

        return $query->get()
            ->map(function (Content $candidate) use ($source, $sourcePlainText): ?array {
                $targetUrl = $this->resolveTargetUrl($candidate);
                if ($targetUrl === '') {
                    return null;
                }

                $similarity = $this->similarityScore($source, $candidate);
                $relationship = $this->relationship($source, $candidate);
                $anchorOptions = $this->anchorOptions($sourcePlainText, $candidate);
                $priority = $this->priorityScore($relationship, $similarity);

                if ($anchorOptions === []) {
                    return null;
                }

                $minSimilarity = (float) config('internal_linking.min_similarity_score', 0.18);
                if ($similarity < $minSimilarity && ! in_array($relationship, ['same_chain_pillar', 'same_chain_supporting', 'same_chain_related'], true)) {
                    return null;
                }

                return [
                    'content' => $candidate,
                    'target_url' => $targetUrl,
                    'similarity_score' => $similarity,
                    'priority_score' => $priority,
                    'relationship' => $relationship,
                    'first_anchor_position' => $this->firstAnchorPosition($sourcePlainText, $anchorOptions),
                    'anchor_options' => $anchorOptions,
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                $priorityComparison = $right['priority_score'] <=> $left['priority_score'];
                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                $positionComparison = $left['first_anchor_position'] <=> $right['first_anchor_position'];
                if ($positionComparison !== 0) {
                    return $positionComparison;
                }

                return strcasecmp((string) $left['content']->title, (string) $right['content']->title);
            })
            ->take($limit)
            ->values();
    }

    public function sourceHtml(Content $content): string
    {
        $content->loadMissing(['drafts' => fn ($query) => $query->latest('created_at')->limit(1), 'currentRevision', 'currentVersion']);

        return trim((string) (
            $content->drafts->first()?->content_html
            ?: $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: ''
        ));
    }

    /**
     * @return array<int,string>
     */
    private function anchorOptions(string $plainText, Content $candidate): array
    {
        if ($plainText === '') {
            return [];
        }

        return collect([
            trim((string) $candidate->primary_keyword),
            trim((string) $candidate->title),
        ])
            ->map(fn (string $phrase): string => trim(preg_replace('/\s+/u', ' ', $phrase) ?? ''))
            ->filter(fn (string $phrase): bool => $phrase !== '' && mb_strlen($phrase) >= 4)
            ->filter(function (string $phrase) use ($plainText): bool {
                return str_contains($plainText, Str::lower($phrase));
            })
            ->unique()
            ->values()
            ->all();
    }

    private function plainText(string $html): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? ''));
    }

    /**
     * @param array<int,string> $anchorOptions
     */
    private function firstAnchorPosition(string $plainText, array $anchorOptions): int
    {
        $positions = collect($anchorOptions)
            ->map(function (string $anchor) use ($plainText): int {
                $position = mb_stripos($plainText, Str::lower($anchor));

                return $position === false ? PHP_INT_MAX : $position;
            })
            ->all();

        return $positions === [] ? PHP_INT_MAX : min($positions);
    }

    private function priorityScore(string $relationship, float $similarity): float
    {
        $relationshipBoost = match ($relationship) {
            'same_chain_pillar' => 3.0,
            'same_chain_supporting' => 2.7,
            'same_chain_related' => 2.2,
            default => 1.0,
        };

        return round(($relationshipBoost * 100) + ($similarity * 100), 3);
    }

    private function relationship(Content $source, Content $candidate): string
    {
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

    private function similarityScore(Content $source, Content $candidate): float
    {
        $sourceTokens = $this->tokens($source);
        $candidateTokens = $this->tokens($candidate);

        if ($sourceTokens === [] || $candidateTokens === []) {
            return 0.0;
        }

        $overlap = count(array_intersect($sourceTokens, $candidateTokens));
        $union = count(array_unique([...$sourceTokens, ...$candidateTokens]));

        if ($union === 0) {
            return 0.0;
        }

        return round($overlap / $union, 4);
    }

    /**
     * @return array<int,string>
     */
    private function tokens(Content $content): array
    {
        $raw = trim(implode(' ', array_filter([
            (string) $content->title,
            (string) $content->primary_keyword,
        ])));

        return collect(preg_split('/[^[:alnum:]]+/u', Str::lower($raw)) ?: [])
            ->map(fn ($token): string => trim((string) $token))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->reject(fn (string $token): bool => in_array($token, ['the', 'and', 'for', 'with', 'from', 'that', 'this'], true))
            ->unique()
            ->values()
            ->all();
    }

    private function resolveTargetUrl(Content $content): string
    {
        $publication = $content->relationLoaded('publications')
            ? $content->publications->first(fn (ContentPublication $publication): bool => $this->isPublishedPublication($publication))
            : $content->publications()
                ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
                ->whereIn('remote_status', $this->publishedRemoteStatuses())
                ->whereNotNull('remote_url')
                ->where('remote_url', '!=', '')
                ->orderByDesc('last_verified_at')
                ->orderByDesc('last_delivered_at')
                ->first();

        return trim((string) ($publication?->remote_url ?? ''));
    }

    private function publishedPublicationQuery($query, string $siteId, string $locale)
    {
        return $query
            ->where('client_site_id', $siteId)
            ->where('locale', $locale)
            ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
            ->whereIn('remote_status', $this->publishedRemoteStatuses())
            ->whereNotNull('remote_url')
            ->where('remote_url', '!=', '')
            ->orderByDesc('last_verified_at')
            ->orderByDesc('last_delivered_at');
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
}
