<?php

namespace App\Data\Llm;

class LlmRequest
{
    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array<string, mixed>|string|null  $responseFormat
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $messages,
        public readonly ?string $systemPrompt = null,
        public readonly ?float $temperature = null,
        public readonly ?int $maxTokens = null,
        public readonly array|string|null $responseFormat = null,
        public readonly ?array $metadata = null,
    ) {}

    /**
     * @return array<int, array{role: string, content: mixed}>
     */
    public function messagesWithSystemPrompt(): array
    {
        if ($this->systemPrompt === null || $this->systemPrompt === '') {
            return $this->messages;
        }

        return [
            ['role' => 'system', 'content' => $this->systemPrompt],
            ...$this->messages,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function withRuntime(
        ?string $provider = null,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?array $metadata = null,
    ): self {
        return new self(
            provider: $provider ?? $this->provider,
            model: $model ?? $this->model,
            messages: $this->messages,
            systemPrompt: $this->systemPrompt,
            temperature: $temperature ?? $this->temperature,
            maxTokens: $maxTokens ?? $this->maxTokens,
            responseFormat: $this->responseFormat,
            metadata: $metadata ?? $this->metadata,
        );
    }
}
