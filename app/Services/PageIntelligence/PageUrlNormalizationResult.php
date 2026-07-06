<?php

namespace App\Services\PageIntelligence;

class PageUrlNormalizationResult
{
    public function __construct(
        public readonly string $inputUrl,
        public readonly string $firstSeenUrl,
        public readonly string $firstSeenUrlHash,
        public readonly string $canonicalUrl,
        public readonly string $canonicalUrlHash,
        public readonly string $scheme,
        public readonly string $domain,
        public readonly string $path,
        public readonly bool $hasCanonicalIdentity = true,
    ) {
    }
}
