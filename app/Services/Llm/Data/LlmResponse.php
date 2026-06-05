<?php

namespace App\Services\Llm\Data;

class LlmResponse
{
    /**
     * @param array<string, mixed>|null $json
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly LlmUsage $usage,
        public readonly string $modelUsed,
        public readonly string $providerName,
        public readonly ?string $requestId = null,
        public readonly array $raw = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'json' => $this->json,
            'usage' => $this->usage->toArray(),
            'model_used' => $this->modelUsed,
            'provider_name' => $this->providerName,
            'request_id' => $this->requestId,
            'raw' => $this->raw,
        ];
    }
}
