<?php

namespace App\Services\AiVisibility;

class SuggestedQuery
{
    public function __construct(
        public readonly string $key,
        public readonly string $queryText,
        public readonly string $category,
        public readonly string $intent,
        public readonly int $confidenceScore,
        public readonly string $explanation,
    ) {
    }

    /**
     * @return array{key:string,query_text:string,category:string,intent:string,confidence_score:int,explanation:string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'query_text' => $this->queryText,
            'category' => $this->category,
            'intent' => $this->intent,
            'confidence_score' => $this->confidenceScore,
            'explanation' => $this->explanation,
        ];
    }
}
