<?php

namespace App\Http\Controllers;

use App\Support\PublicSiteContext;
use Illuminate\Http\Response;

class PublicRobotsController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /app',
            'Disallow: /billing',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /preview',
            'Disallow: /drafts',
            'Disallow: /api',
            'Sitemap: ' . $this->sitemapUrl(),
            '',
        ];

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function sitemapUrl(): string
    {
        if (app()->bound(PublicSiteContext::class)) {
            $context = app(PublicSiteContext::class);

            return rtrim((string) $context->rootUrl, '/') . '/sitemap.xml';
        }

        return route('sitemaps.index');
    }
}
