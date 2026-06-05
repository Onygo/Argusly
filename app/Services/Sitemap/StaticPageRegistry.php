<?php

namespace App\Services\Sitemap;

class StaticPageRegistry
{
    /**
     * @return array<int,string>
     */
    public function routeNames(): array
    {
        return collect((array) config('sitemap.static_routes', []))
            ->map(fn ($routeName): string => trim((string) $routeName))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
