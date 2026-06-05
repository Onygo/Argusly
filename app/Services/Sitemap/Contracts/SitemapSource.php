<?php

namespace App\Services\Sitemap\Contracts;

interface SitemapSource
{
    public function name(): string;

    public function enabled(): bool;

    /**
     * @return array<int,array{loc:string,lastmod:?string}>
     */
    public function urls(?string $locale = null): array;
}
