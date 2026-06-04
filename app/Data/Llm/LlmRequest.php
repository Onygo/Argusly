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
}
