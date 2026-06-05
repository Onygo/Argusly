<?php

namespace App\Http\Controllers;

use App\Models\ProductUpdate;
use App\Support\SafeMarkdownRenderer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicProductUpdatesController extends Controller
{
    public function index(Request $request, SafeMarkdownRenderer $renderer): View
    {
        $locale = (string) app()->getLocale();
        $search = trim((string) $request->query('q', ''));
        $tag = ProductUpdate::normalizeTag((string) $request->query('tag', ''));

        $updates = ProductUpdate::query()
            ->publicVisible()
            ->tagged($tag)
            ->search($search)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        $updates->getCollection()->transform(function (ProductUpdate $update) use ($renderer): ProductUpdate {
            $update->setAttribute('body_html', $renderer->render((string) $update->body_markdown));

            return $update;
        });

        $availableTags = ProductUpdate::query()
            ->publicVisible()
            ->orderByDesc('published_at')
            ->limit(300)
            ->get(['tags'])
            ->pluck('tags')
            ->flatten()
            ->filter(fn ($item): bool => trim((string) $item) !== '')
            ->map(fn ($item): string => (string) $item)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return view('public.product-updates.index', [
            'updates' => $updates,
            'activeTag' => $tag,
            'searchTerm' => $search,
            'availableTags' => $availableTags,
            'metaTitle' => __('public.product_updates.title').' | Argusly',
            'metaDescription' => __('public.product_updates.subtitle'),
            'canonicalUrl' => $this->localizedRoute('public.product_updates.index', [], $locale),
            'ogType' => 'website',
        ]);
    }

    private function localizedRoute(string $name, array $params, string $locale): string
    {
        if ($locale !== 'nl') {
            $params['lang'] = $locale;
        }

        return route($name, $params);
    }
}
