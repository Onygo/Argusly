<?php

namespace App\Data\Llm;

class LlmResponse
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $content,
        public readonly ?array $rawResponse = null,
        public readonly ?LlmUsage $usage = null,
        public readonly ?string $finishReason = null,
        public readonly ?int $latencyMs = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'content' => $this->content,
            'raw_response' => $this->rawResponse,
            'usage' => $this->usage?->toArray(),
            'finish_reason' => $this->finishReason,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
