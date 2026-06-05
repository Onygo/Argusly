<?php

namespace App\Services\Sitemap;

class SitemapGenerator
{
    public function __construct(
        private readonly SitemapManifestBuilder $manifestBuilder,
        private readonly SitemapIndexGenerator $indexGenerator,
        private readonly SitemapCacheManager $cacheManager,
    ) {}

    /**
     * @return array{manifest:array<int,array{name:string,lastmod:?string,type:string,url_count:int}>,children:array<string,string>,index:string}
     */
    public function generate(string $scope = 'default', bool $flush = false, ?string $locale = null): array
    {
        if ($flush) {
            $this->cacheManager->forget($scope);
        }

        $payload = $this->manifestBuilder->build($locale);
        $indexXml = $this->indexGenerator->generate($payload['manifest'], $locale);

        $this->cacheManager->put($scope, $payload['manifest'], $indexXml, $payload['children']);

        return [
            'manifest' => $payload['manifest'],
            'children' => $payload['children'],
            'index' => $indexXml,
        ];
    }

    public function indexXml(string $scope = 'default', ?string $locale = null): string
    {
        $cached = $this->cacheManager->getIndexXml($scope);
        if ($cached !== null) {
            return $cached;
        }

        return $this->generate($scope, false, $locale)['index'];
    }

    public function childXml(string $name, string $scope = 'default', ?string $locale = null): ?string
    {
        $cached = $this->cacheManager->getChildXml($scope, $name);
        if ($cached !== null) {
            return $cached;
        }

        $this->generate($scope, false, $locale);

        return $this->cacheManager->getChildXml($scope, $name);
    }

    /**
     * @return array<int,array{name:string,lastmod:?string,type:string,url_count:int}>
     */
    public function manifest(string $scope = 'default', ?string $locale = null): array
    {
        $cached = $this->cacheManager->getManifest($scope);
        if (is_array($cached)) {
            return (array) ($cached['items'] ?? []);
        }

        return $this->generate($scope, false, $locale)['manifest'];
    }
}
