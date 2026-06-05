<?php

namespace App\Services\Seo\Providers;

class NoneProvider implements SEOProviderInterface
{
    public function key(): string
    {
        return 'none';
    }

    public function supportsMetaTitle(): bool
    {
        return false;
    }

    public function supportsMetaDescription(): bool
    {
        return false;
    }

    public function supportsCanonical(): bool
    {
        return false;
    }

    public function supportsOgTags(): bool
    {
        return false;
    }

    /**
     * @return array<int,string>
     */
    public function syncableFieldKeys(): array
    {
        return [];
    }

    /**
     * @param array<string,mixed> $seo
     * @return array<string,string>
     */
    public function mapToWordPressMeta(array $seo): array
    {
        return [];
    }
}
