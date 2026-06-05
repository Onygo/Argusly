<?php

namespace App\Services\InternalLinking;

use App\Models\Content;
use App\Models\Draft;
use Illuminate\Support\Collection;

class InternalLinkingService
{
    public function __construct(
        private readonly InternalLinkSuggestionService $suggestionService,
        private readonly InternalLinkInjector $injector,
    ) {
    }

    /**
     * @return array{
     *   suggestions:array<int,array<string,string>>,
     *   applied_suggestions:array<int,array<string,string>>,
     *   applied_count:int,
     *   updated:bool
     * }
     */
    public function generateForContent(Content $content): array
    {
        if (! config('internal_linking.enabled', true)) {
            return [
                'suggestions' => [],
                'applied_suggestions' => [],
                'applied_count' => 0,
                'updated' => false,
            ];
        }

        $content->loadMissing(['drafts' => fn ($query) => $query->latest('created_at')->limit(1)]);
        /** @var Draft|null $draft */
        $draft = $content->drafts->first();
        if (! $draft || trim((string) $draft->content_html) === '') {
            return [
                'suggestions' => [],
                'applied_suggestions' => [],
                'applied_count' => 0,
                'updated' => false,
            ];
        }

        $suggestions = $this->suggestionService->suggestFor($content, (string) $draft->content_html);
        $supplementalLinks = $this->manualRelatedLinks($content, $draft);

        if ($suggestions->isEmpty() && $supplementalLinks === []) {
            return [
                'suggestions' => [],
                'applied_suggestions' => [],
                'applied_count' => 0,
                'updated' => false,
            ];
        }

        $applied = [
            'applied_count' => 0,
            'updated_html' => (string) $draft->content_html,
            'applied_suggestions' => [],
        ];

        if ((bool) config('internal_linking.inject_into_html', true)) {
            $applied = $this->injector->inject($content, $draft, $suggestions, $supplementalLinks);
        }

        $content->update([
            'internal_links_meta' => [
                'source' => 'internal_linking_engine',
                'generated_at' => now()->toIso8601String(),
                'source_draft_id' => (string) $draft->id,
                'suggestions' => $suggestions->map(fn ($suggestion) => $suggestion->toArray())->values()->all(),
                'supplemental_links' => $supplementalLinks,
                'applied_suggestions' => $applied['applied_suggestions'],
                'applied_count' => (int) $applied['applied_count'],
            ],
        ]);

        return [
            'suggestions' => $suggestions->map(fn ($suggestion) => $suggestion->toArray())->values()->all(),
            'applied_suggestions' => $applied['applied_suggestions'],
            'applied_count' => (int) $applied['applied_count'],
            'updated' => (int) $applied['applied_count'] > 0,
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function manualRelatedLinks(Content $content, Draft $draft): array
    {
        $rows = data_get($draft->meta, 'related_articles');
        if (! is_array($rows)) {
            $rows = data_get($content->draftVersion?->meta, 'related_articles', []);
        }

        $locale = $content->localeCode();

        return collect(is_array($rows) ? $rows : [])
            ->map(function (mixed $row) use ($content, $locale): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $relatedId = trim((string) ($row['source_publishlayer_id'] ?? $row['id'] ?? ''));
                $relatedTitle = trim((string) ($row['title'] ?? ''));
                $relatedUrl = trim((string) ($row['target_url'] ?? $row['href'] ?? $row['url'] ?? ''));

                if ($relatedUrl === '' && $relatedId !== '') {
                    $relatedContent = Content::query()->find($relatedId);

                    if ($relatedContent instanceof Content && $relatedContent->client_site_id === $content->client_site_id) {
                        if ($locale !== '' && $relatedContent->localeCode() !== $locale) {
                            return null;
                        }

                        $relatedUrl = trim((string) ($relatedContent->published_url ?: $relatedContent->seo_canonical ?: ''));
                        $relatedTitle = $relatedTitle !== '' ? $relatedTitle : trim((string) $relatedContent->title);
                    }
                }

                if ($relatedUrl === '') {
                    return null;
                }

                $anchorText = trim((string) ($row['anchor_text'] ?? $relatedTitle));

                return [
                    'target_content_id' => $relatedId,
                    'target_url' => $relatedUrl,
                    'anchor_text' => $anchorText,
                    'title' => $relatedTitle !== '' ? $relatedTitle : $anchorText,
                    'reason' => 'manual_related_article',
                ];
            })
            ->filter()
            ->unique(fn (array $row): string => trim((string) $row['target_url']))
            ->values()
            ->all();
    }
}
