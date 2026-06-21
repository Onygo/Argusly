<?php

namespace App\Http\Controllers;

use App\Models\MarketingPage;
use App\Models\MarketingPageTranslation;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MarketingPageController extends Controller
{
    public function show(Request $request, string $slug): View|RedirectResponse
    {
        $locale = (string) app()->getLocale();
        $resolvedSection = trim((string) ($request->route('section') ?: $request->route('sectionSlug')));
        $resolvedSection = $resolvedSection !== '' ? $resolvedSection : null;

        $translation = MarketingPageTranslation::query()
            ->with('marketingPage.translations')
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->whereHas('marketingPage', function ($query) use ($resolvedSection): void {
                $query->where('is_active', true);

                if ($resolvedSection === null) {
                    $query->whereNull('section');

                    return;
                }

                $query->where('section', $resolvedSection);
            })
            ->firstOrFail();

        $page = $translation->marketingPage;
        $content = is_array($translation->content) ? $translation->content : [];
        $canonicalUrl = LocalizedMarketingUrl::page($page, $locale);

        $resolvePage = function (string $pageKey) use ($locale): ?array {
            $relatedPage = MarketingPage::query()
                ->where('key', $pageKey)
                ->with('translations')
                ->first();

            if (! $relatedPage instanceof MarketingPage) {
                return null;
            }

            $relatedTranslation = $relatedPage->translation($locale);
            if (! $relatedTranslation instanceof MarketingPageTranslation) {
                return null;
            }

            return [
                'page' => $relatedPage,
                'title' => $relatedTranslation->title,
                'url' => LocalizedMarketingUrl::page($relatedPage, $locale),
            ];
        };

        if ($request->fullUrlIs($canonicalUrl) === false && trim((string) $translation->canonical_path) !== '') {
            return redirect()->to($canonicalUrl, 301);
        }

        $relatedTopics = collect((array) data_get($content, 'related_page_keys', []))
            ->map(fn (string $pageKey): ?array => $resolvePage($pageKey))
            ->filter()
            ->map(fn (array $pageLink): array => [
                'title' => $pageLink['title'],
                'url' => $pageLink['url'],
            ])
            ->values()
            ->all();

        $sections = collect((array) ($content['sections'] ?? []))
            ->map(function (array $section) use ($resolvePage): array {
                if (empty($section['cards'] ?? [])) {
                    return $section;
                }

                $section['cards'] = collect((array) $section['cards'])
                    ->map(function (array $card) use ($resolvePage): ?array {
                        $pageKey = trim((string) ($card['page_key'] ?? ''));
                        $resolvedPage = $pageKey !== '' ? $resolvePage($pageKey) : null;
                        $title = trim((string) ($card['title'] ?? ($resolvedPage['title'] ?? '')));
                        $url = trim((string) ($card['url'] ?? ($resolvedPage['url'] ?? '')));

                        if ($title === '' || $url === '') {
                            return null;
                        }

                        return [
                            'title' => $title,
                            'description' => trim((string) ($card['description'] ?? '')),
                            'url' => $url,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();

                return $section;
            })
            ->all();

        $platformLinks = collect((array) data_get($content, 'platform_links', []))
            ->map(function (array $link) use ($locale): ?array {
                $routeName = trim((string) ($link['route'] ?? ''));
                if ($routeName === '') {
                    return null;
                }

                return [
                    'label' => trim((string) ($link['label'] ?? '')),
                    'url' => LocalizedMarketingUrl::route($routeName, (array) ($link['params'] ?? []), $locale),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $hubPageKey = trim((string) data_get($content, 'hub_page_key', ''));
        $hubPage = $page->key === 'ai_search'
            ? [
                'title' => __('public.resources.ai_search_geo'),
                'url' => $canonicalUrl,
            ]
            : ($hubPageKey !== '' ? $resolvePage($hubPageKey) : null);

        $breadcrumbs = [[
            'label' => __('public.nav.resources'),
            'url' => null,
        ]];

        if (is_array($hubPage)) {
            $breadcrumbs[] = [
                'label' => $hubPage['title'],
                'url' => $hubPage['url'],
            ];
        }

        if ($page->key !== 'ai_search') {
            $breadcrumbs[] = [
                'label' => $translation->title,
                'url' => $canonicalUrl,
            ];
        }

        return view('public.marketing-topic', [
            'page' => $page,
            'translation' => $translation,
            'content' => array_merge($content, ['sections' => $sections]),
            'metaTitle' => $translation->seo_title ?: $translation->title . ' | Argusly',
            'metaDescription' => $translation->meta_description,
            'canonicalUrl' => $canonicalUrl,
            'hreflangUrls' => LocalizedMarketingUrl::hreflangsForPage($page),
            'breadcrumbs' => $breadcrumbs,
            'relatedTopics' => $relatedTopics,
            'platformLinks' => $platformLinks,
            'cta' => (array) data_get($content, 'cta', []),
        ]);
    }

    public function markdown(Request $request, string $slug): Response|RedirectResponse
    {
        $locale = (string) app()->getLocale();

        $translation = MarketingPageTranslation::query()
            ->with('marketingPage.translations')
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->whereHas('marketingPage', fn ($query) => $query->where('is_active', true)->whereNull('section'))
            ->firstOrFail();

        $page = $translation->marketingPage;
        $canonicalUrl = LocalizedMarketingUrl::page($page, $locale);
        $markdownUrl = LocalizedMarketingUrl::route('public.marketing-pages.markdown', ['slug' => $slug], $locale);

        if ($request->fullUrlIs($markdownUrl) === false) {
            return redirect()->to($markdownUrl, 301);
        }

        return response(
            $this->buildMarkdownDocument($translation),
            200,
            ['Content-Type' => 'text/markdown; charset=UTF-8']
        );
    }

    private function buildMarkdownDocument(MarketingPageTranslation $translation): string
    {
        $content = is_array($translation->content) ? $translation->content : [];
        $sections = [];

        $sections[] = '# ' . trim((string) $translation->title);

        if (trim((string) ($content['subheadline'] ?? '')) !== '') {
            $sections[] = trim((string) $content['subheadline']);
        }

        if (trim((string) ($content['intro'] ?? '')) !== '') {
            $sections[] = trim((string) $content['intro']);
        }

        foreach ((array) ($content['sections'] ?? []) as $section) {
            $parts = [];
            $title = trim((string) ($section['title'] ?? ''));

            if ($title !== '') {
                $parts[] = '## ' . $title;
            }

            if (trim((string) ($section['intro'] ?? '')) !== '') {
                $parts[] = trim((string) $section['intro']);
            }

            foreach ((array) ($section['paragraphs'] ?? []) as $paragraph) {
                $parts[] = trim((string) $paragraph);
            }

            if (! empty($section['steps'] ?? [])) {
                foreach ((array) $section['steps'] as $step) {
                    $stepTitle = trim((string) ($step['title'] ?? ''));
                    $stepText = trim((string) ($step['text'] ?? ''));

                    if ($stepTitle !== '') {
                        $parts[] = '### ' . $stepTitle;
                    }

                    if ($stepText !== '') {
                        $parts[] = $stepText;
                    }
                }
            }

            if (! empty($section['bullets'] ?? [])) {
                $parts[] = collect((array) $section['bullets'])
                    ->map(fn (string $bullet): string => '- ' . trim($bullet))
                    ->implode("\n");
            }

            if (! empty($section['table']['headers'] ?? []) && ! empty($section['table']['rows'] ?? [])) {
                $headers = array_values((array) $section['table']['headers']);
                $rows = array_values((array) $section['table']['rows']);

                $table = [];
                $table[] = '| ' . implode(' | ', $headers) . ' |';
                $table[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';

                foreach ($rows as $row) {
                    $table[] = '| ' . implode(' | ', array_map(fn ($value): string => trim((string) $value), (array) $row)) . ' |';
                }

                $parts[] = implode("\n", $table);
            }

            if (! empty($section['cards'] ?? [])) {
                foreach ((array) $section['cards'] as $card) {
                    $cardTitle = trim((string) ($card['title'] ?? ''));
                    $cardDescription = trim((string) ($card['description'] ?? ''));

                    if ($cardTitle !== '') {
                        $parts[] = '### ' . $cardTitle;
                    }

                    if ($cardDescription !== '') {
                        $parts[] = $cardDescription;
                    }
                }
            }

            if ($parts !== []) {
                $sections[] = implode("\n\n", $parts);
            }
        }

        $faqItems = collect((array) ($content['faq'] ?? []))
            ->filter(fn (array $item): bool => trim((string) ($item['question'] ?? '')) !== '' && trim((string) ($item['answer'] ?? '')) !== '');

        if ($faqItems->isNotEmpty()) {
            $faqSections = ['## FAQ'];

            foreach ($faqItems as $item) {
                $faqSections[] = '### ' . trim((string) $item['question']);
                $faqSections[] = trim((string) $item['answer']);
            }

            $sections[] = implode("\n\n", $faqSections);
        }

        return trim(implode("\n\n", $sections)) . "\n";
    }
}
