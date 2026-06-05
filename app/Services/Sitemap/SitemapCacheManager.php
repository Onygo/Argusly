<?php

namespace App\Services\Sitemap;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class SitemapCacheManager
{
    public function getManifest(string $scope): ?array
    {
        /** @var array<string,mixed>|null $manifest */
        $manifest = $this->store()->get($this->manifestKey($scope));

        return is_array($manifest) ? $manifest : null;
    }

    public function getIndexXml(string $scope): ?string
    {
        $xml = $this->store()->get($this->indexKey($scope));

        return is_string($xml) && $xml !== '' ? $xml : null;
    }

    public function getChildXml(string $scope, string $name): ?string
    {
        $xml = $this->store()->get($this->childKey($scope, $name));

        return is_string($xml) && $xml !== '' ? $xml : null;
    }

    /**
     * @param  array<int,array{name:string,lastmod:?string,type:string,url_count:int}>  $manifest
     * @param  array<string,string>  $children
     */
    public function put(string $scope, array $manifest, string $indexXml, array $children): void
    {
        $payload = [
            'items' => $manifest,
            'generated_at' => now()->toAtomString(),
        ];

        $this->putValue($this->manifestKey($scope), $payload);
        $this->putValue($this->indexKey($scope), $indexXml);

        foreach ($children as $name => $xml) {
            $this->putValue($this->childKey($scope, $name), $xml);
        }
    }

    public function forget(string $scope): void
    {
        $manifest = $this->getManifest($scope);

        $this->store()->forget($this->manifestKey($scope));
        $this->store()->forget($this->indexKey($scope));

        foreach ((array) data_get($manifest, 'items', []) as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $this->store()->forget($this->childKey($scope, $name));
            }
        }
    }

    private function putValue(string $key, mixed $value): void
    {
        $ttl = (int) config('sitemap.cache_ttl', 3600);

        if ($ttl <= 0) {
            $this->store()->forever($key, $value);

            return;
        }

        $this->store()->put($key, $value, $ttl);
    }

    private function store(): Repository
    {
        $store = config('sitemap.cache_store');

        return is_string($store) && $store !== ''
            ? Cache::store($store)
            : Cache::store();
    }

    private function manifestKey(string $scope): string
    {
        return $this->prefix($scope) . ':manifest';
    }

    private function indexKey(string $scope): string
    {
        return $this->prefix($scope) . ':index';
    }

    private function childKey(string $scope, string $name): string
    {
        return $this->prefix($scope) . ':child:' . $name;
    }

    private function prefix(string $scope): string
    {
        return trim((string) config('sitemap.cache_prefix', 'publishlayer:sitemap'), ':') . ':' . $scope;
    }
}
