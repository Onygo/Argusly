<?php

namespace App\DTO\CompanyIntelligence;

class CompanyIntelligenceProfileData
{
    public function __construct(
        public readonly array $payload,
        public readonly string $payloadHash,
        public readonly int $completenessScore,
        public readonly array $completenessBreakdown,
        public readonly string $embeddingText,
    ) {
    }

    public function toArray(): array
    {
        return [
            'payload' => $this->payload,
            'payload_hash' => $this->payloadHash,
            'completeness_score' => $this->completenessScore,
            'completeness_breakdown' => $this->completenessBreakdown,
            'embedding_text' => $this->embeddingText,
        ];
    }
}
