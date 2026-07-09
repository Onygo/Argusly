<?php

namespace App\Services\PageIntelligence\Scoring;

class ScoreComponent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?float $score,
        public readonly bool $available,
        public readonly float $weight,
        public readonly float $weighted,
        public readonly float $confidence,
        public readonly string $source,
        public readonly string $explanation,
        public readonly ScoreEvidence $evidence,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'score' => $this->score,
            'available' => $this->available,
            'weight' => $this->weight,
            'weighted' => $this->weighted,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'explanation' => $this->explanation,
            'evidence' => $this->evidence->toArray(),
            'metadata' => $this->metadata,
        ];
    }
}
