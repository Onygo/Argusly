<?php

namespace App\Services\Sitemap;

use XMLWriter;

class SitemapIndexGenerator
{
    public function __construct(
        private readonly SitemapUrlResolver $urlResolver,
    ) {}

    /**
     * @param  array<int,array{name:string,lastmod:?string,type:string,url_count:int}>  $manifest
     */
    public function generate(array $manifest, ?string $locale = null): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($manifest as $entry) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $this->urlResolver->childSitemapUrl($entry['name'], $locale));

            if (! empty($entry['lastmod'])) {
                $xml->writeElement('lastmod', $entry['lastmod']);
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
