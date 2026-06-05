<?php

namespace App\Services\InternalLinking;

use App\Data\InternalLinkSuggestion;
use App\Jobs\RebuildContentMarkdownArtifactJob;
use App\Models\Content;
use App\Models\Draft;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Content\InternalLinkPlacementService;
use Illuminate\Support\Collection;

class InternalLinkInjector
{
    public function __construct(
        private readonly InternalLinkPlacementService $placement,
        private readonly ContentCacheInvalidationService $cacheInvalidation,
    ) {}

    /**
     * @param Collection<int,InternalLinkSuggestion>|array<int,InternalLinkSuggestion> $suggestions
     * @param array<int,array<string,mixed>> $supplementalLinks
     * @return array{applied_count:int,updated_html:string,applied_suggestions:array<int,array<string,string>>,inline_links:array<int,array<string,string>>,fallback_links:array<int,array<string,string>>}
     */
    public function injectIntoHtml(string $html, Collection|array $suggestions, array $supplementalLinks = []): array
    {
        $entries = collect($suggestions)
            ->filter(fn ($suggestion) => $suggestion instanceof InternalLinkSuggestion)
            ->values()
            ->all();

        $result = $this->placement->placeIntoHtml($html, array_merge($entries, $supplementalLinks));
        $applied = array_values(array_merge($result['inline_links'], $result['fallback_links']));

        return [
            'applied_count' => count($applied),
            'updated_html' => $result['updated_html'] !== '' ? $result['updated_html'] : trim($html),
            'applied_suggestions' => $applied,
            'inline_links' => $result['inline_links'],
            'fallback_links' => $result['fallback_links'],
        ];
    }

    /**
     * @param Collection<int,InternalLinkSuggestion>|array<int,InternalLinkSuggestion> $suggestions
     * @param array<int,array<string,mixed>> $supplementalLinks
     * @return array{applied_count:int,updated_html:string,applied_suggestions:array<int,array<string,string>>,inline_links:array<int,array<string,string>>,fallback_links:array<int,array<string,string>>}
     */
    public function inject(Content $content, Draft $draft, Collection|array $suggestions, array $supplementalLinks = []): array
    {
        $originalHtml = trim((string) ($draft->content_html ?? ''));
        $result = $this->injectIntoHtml($originalHtml, $suggestions, $supplementalLinks);

        if ($result['updated_html'] !== '' && $result['updated_html'] !== $originalHtml) {
            $draft->update([
                'content_html' => $result['updated_html'],
                'meta' => $this->withInternalLinkMeta((array) ($draft->meta ?? []), $result),
            ]);

            $content->loadMissing(['currentRevision', 'currentVersion']);

            if ($content->currentRevision) {
                $content->currentRevision->update([
                    'content_html' => $result['updated_html'],
                    'meta' => $this->withInternalLinkMeta((array) ($content->currentRevision->meta ?? []), $result),
                ]);
            }

            if ($content->currentVersion) {
                $content->currentVersion->update([
                    'body' => $result['updated_html'],
                    'meta' => $this->withInternalLinkMeta((array) ($content->currentVersion->meta ?? []), $result),
                ]);
            }

            $content->forceFill([
                'internal_links_meta' => $this->withInternalLinkMeta((array) ($content->internal_links_meta ?? []), $result),
            ])->save();

            RebuildContentMarkdownArtifactJob::dispatch((string) $content->id, force: true)->afterCommit();
            $this->cacheInvalidation->invalidateContent($content->fresh(), 'content.internal_links_applied');
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withInternalLinkMeta(array $meta, array $result): array
    {
        $inlineLinks = array_values((array) ($result['inline_links'] ?? []));
        $fallbackLinks = array_values((array) ($result['fallback_links'] ?? []));

        $meta['inline_links_applied'] = $inlineLinks !== [];
        $meta['internal_links_applied_at'] = now()->toIso8601String();
        $meta['inserted_inline_links'] = $this->uniqueLinks(array_merge(
            (array) ($meta['inserted_inline_links'] ?? []),
            $inlineLinks,
        ));
        $meta['fallback_links'] = $this->uniqueLinks($fallbackLinks);

        return $meta;
    }

    /**
     * @param array<int,mixed> $links
     * @return array<int,array<string,string>>
     */
    private function uniqueLinks(array $links): array
    {
        return collect($links)
            ->filter(fn ($link): bool => is_array($link))
            ->map(fn (array $link): array => [
                'target_content_id' => trim((string) ($link['target_content_id'] ?? '')),
                'target_url' => trim((string) ($link['target_url'] ?? '')),
                'anchor_text' => trim((string) ($link['anchor_text'] ?? '')),
                'title' => trim((string) ($link['title'] ?? '')),
            ])
            ->filter(fn (array $link): bool => $link['target_url'] !== '')
            ->unique(fn (array $link): string => $link['target_url'])
            ->values()
            ->all();
    }
}
