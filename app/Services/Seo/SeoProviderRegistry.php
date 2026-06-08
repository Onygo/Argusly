<?php

namespace App\Services\Seo;

use App\Services\Seo\Providers\AioSeoProvider;
use App\Services\Seo\Providers\NoneProvider;
use App\Services\Seo\Providers\ArguslyProvider;
use App\Services\Seo\Providers\RankMathProvider;
use App\Services\Seo\Providers\SEOProviderInterface;
use App\Services\Seo\Providers\YoastProvider;

class SeoProviderRegistry
{
    /**
     * @return array<string,SEOProviderInterface>
     */
    public function all(): array
    {
        $providers = [
            new YoastProvider(),
            new RankMathProvider(),
            new AioSeoProvider(),
            new ArguslyProvider(),
            new NoneProvider(),
        ];

        $map = [];
        foreach ($providers as $provider) {
            $map[$provider->key()] = $provider;
        }

        return $map;
    }

    public function resolve(?string $provider): SEOProviderInterface
    {
        $normalized = strtolower(trim((string) $provider));
        $providers = $this->all();

        return $providers[$normalized] ?? $providers['none'];
    }
}
