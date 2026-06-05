<?php

namespace App\DTO\LinkIntelligence;

class EmbeddingResult
{
    /**
     * @param array<int, float> $embedding
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $embedding,
    ) {}
}
