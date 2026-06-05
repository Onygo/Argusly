<?php

namespace App\Http\Controllers;

use App\Services\Sitemap\SitemapGenerator;
use App\Support\MarketingRouteSegments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class SitemapController extends Controller
{
    public function __construct(
        private readonly MarketingRouteSegments $segments,
    ) {}

    public function index(Request $request, SitemapGenerator $generator): Response
    {
        abort_unless((bool) config('sitemap.enabled', true), 404);

        try {
            return response(
                $generator->indexXml($this->scopeFromRequest($request), $this->localeFromRequest($request)),
                200,
                ['Content-Type' => 'application/xml; charset=UTF-8']
            );
        } catch (Throwable $e) {
            Log::error('seo.sitemap_generation_failed', [
                'type' => 'index',
                'scope' => $this->scopeFromRequest($request),
                'locale' => $this->localeFromRequest($request),
                'message' => $e->getMessage(),
            ]);

            return response('Sitemap unavailable.', 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

    public function show(Request $request, SitemapGenerator $generator): Response
    {
        abort_unless((bool) config('sitemap.enabled', true), 404);
        $name = trim((string) $request->route('name'));

        try {
            $xml = $generator->childXml($name, $this->scopeFromRequest($request), $this->localeFromRequest($request));
            abort_if($xml === null, 404);

            return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
        } catch (Throwable $e) {
            Log::error('seo.sitemap_generation_failed', [
                'type' => 'child',
                'name' => $name,
                'scope' => $this->scopeFromRequest($request),
                'locale' => $this->localeFromRequest($request),
                'message' => $e->getMessage(),
            ]);

            return response('Sitemap unavailable.', 503, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

    private function scopeFromRequest(Request $request): string
    {
        $host = trim((string) $request->getHost());
        $scope = $host !== '' ? $host : 'default';
        $locale = $this->localeFromRequest($request);

        return $locale !== null ? $scope . ':' . $locale : $scope;
    }

    private function localeFromRequest(Request $request): ?string
    {
        $locale = trim((string) $request->route('locale'));

        if ($locale === '' || ! $this->segments->isSupportedLocale($locale)) {
            return null;
        }

        return $this->segments->resolveLocale($locale);
    }
}
