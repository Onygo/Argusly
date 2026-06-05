<?php

namespace App\DTO\LinkIntelligence;

class ApplyOptions
{
    /**
     * @param array<int, string> $footnoteLinkIds
     */
    public function __construct(
        public readonly string $placement,
        public readonly ?string $anchorText = null,
        public readonly ?string $customUrl = null,
        public readonly array $footnoteLinkIds = [],
    ) {}
}
