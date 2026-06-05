<?php

namespace App\Services\Seo\Providers;

interface SEOProviderInterface
{
    public function key(): string;

    public function supportsMetaTitle(): bool;

    public function supportsMetaDescription(): bool;

    public function supportsCanonical(): bool;

    public function supportsOgTags(): bool;

    /**
     * @return array<int,string>
     */
    public function syncableFieldKeys(): array;

    /**
     * @param array<string,mixed> $seo
     * @return array<string,string>
     */
    public function mapToWordPressMeta(array $seo): array;
}
