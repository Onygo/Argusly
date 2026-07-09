<?php

namespace App\Services\PageIntelligence\Scoring;

class ScoreExplanation
{
    /**
     * @param  array<string, string>  $componentExplanations
     * @param  array<int, string>  $missingInputs
     */
    public function __construct(
        public readonly string $summary,
        public readonly string $method,
        public readonly string $modelKey,
        public readonly string $modelVersion,
        public readonly array $componentExplanations,
        public readonly array $missingInputs,
        public readonly ScoreEvidence $evidence,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'method' => $this->method,
            'model_key' => $this->modelKey,
            'model_version' => $this->modelVersion,
            'component_explanations' => $this->componentExplanations,
            'missing_inputs' => $this->missingInputs,
            'evidence' => $this->evidence->toArray(),
        ];
    }
}
