<?php

namespace App\Services\Llm\Data;

class LlmRequest
{
    /**
     * @param array<int, LlmMessage> $messages
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $model = null,
        public readonly ?float $temperature = null,
        public readonly ?int $maxTokens = null,
        public readonly ?float $topP = null,
        public readonly string $responseFormat = 'text',
        public readonly array $metadata = [],
    ) {
    }
}
