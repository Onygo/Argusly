<?php

namespace App\Services\Sitemap;

use XMLWriter;

class SitemapChunkGenerator
{
    /**
     * @param  array<int,array{loc:string,lastmod:?string,alternates?:array<int,array{hreflang:string,href:string}>,images?:array<int,array{loc:string,title?:string}>}>  $urls
     * @return array<int,array{name:string,xml:string,lastmod:?string,url_count:int}>
     */
    public function generate(string $type, array $urls, int $chunkSize): array
    {
        $urls = array_values($urls);
        if ($urls === []) {
            return [];
        }

        $chunks = array_chunk($urls, max(1, $chunkSize));
        $useIndexedNames = count($chunks) > 1;

        return collect($chunks)
            ->values()
            ->map(function (array $chunk, int $index) use ($type, $useIndexedNames): array {
                $name = $useIndexedNames ? sprintf('%s-%d', $type, $index + 1) : $type;

                return [
                    'name' => $name,
                    'xml' => $this->renderUrlSet($chunk),
                    'lastmod' => collect($chunk)
                        ->pluck('lastmod')
                        ->filter()
                        ->sortDesc()
                        ->first(),
                    'url_count' => count($chunk),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int,array{loc:string,lastmod:?string,alternates?:array<int,array{hreflang:string,href:string}>,images?:array<int,array{loc:string,title?:string}>}>  $urls
     */
    private function renderUrlSet(array $urls): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        if (collect($urls)->contains(fn (array $entry): bool => ! empty($entry['alternates'] ?? []))) {
            $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        }
        if (collect($urls)->contains(fn (array $entry): bool => ! empty($entry['images'] ?? []))) {
            $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
        }

        foreach ($urls as $entry) {
            $xml->startElement('url');
            $xml->writeElement('loc', $entry['loc']);

            if (! empty($entry['lastmod'])) {
                $xml->writeElement('lastmod', $entry['lastmod']);
            }

            foreach ((array) ($entry['alternates'] ?? []) as $alternate) {
                $xml->startElementNs('xhtml', 'link', null);
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', (string) ($alternate['hreflang'] ?? ''));
                $xml->writeAttribute('href', (string) ($alternate['href'] ?? ''));
                $xml->endElement();
            }

            foreach ((array) ($entry['images'] ?? []) as $image) {
                $loc = trim((string) ($image['loc'] ?? ''));
                if ($loc === '') {
                    continue;
                }

                $xml->startElementNs('image', 'image', null);
                $xml->writeElementNs('image', 'loc', null, $loc);
                $title = trim((string) ($image['title'] ?? ''));
                if ($title !== '') {
                    $xml->writeElementNs('image', 'title', null, $title);
                }
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
