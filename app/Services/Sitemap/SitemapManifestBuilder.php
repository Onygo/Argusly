<?php

namespace App\Services\Sitemap;

use App\Services\Sitemap\Contracts\SitemapSource;

class SitemapManifestBuilder
{
    /**
     * @param  iterable<int,SitemapSource>  $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly SitemapChunkGenerator $chunkGenerator,
    ) {}

    /**
     * @return array{manifest:array<int,array{name:string,lastmod:?string,type:string,url_count:int}>,children:array<string,string>}
     */
    public function build(?string $locale = null): array
    {
        $manifest = [];
        $children = [];
        $chunkSize = max(1, (int) config('sitemap.chunk_size', 500));

        foreach ($this->sources as $source) {
            if (! $source->enabled()) {
                continue;
            }

            $urls = $source->urls($locale);
            if ($urls === []) {
                continue;
            }

            $sourceChunkSize = $source->name() === 'articles' ? $chunkSize : max(count($urls), 1);

            foreach ($this->chunkGenerator->generate($source->name(), $urls, $sourceChunkSize) as $chunk) {
                $manifest[] = [
                    'name' => $chunk['name'],
                    'type' => $source->name(),
                    'lastmod' => $chunk['lastmod'],
                    'url_count' => $chunk['url_count'],
                ];
                $children[$chunk['name']] = $chunk['xml'];
            }
        }

        $manifest = collect($manifest)
            ->sortBy('name')
            ->values()
            ->all();

        ksort($children);

        return [
            'manifest' => $manifest,
            'children' => $children,
        ];
    }
}
