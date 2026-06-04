<?php

namespace App\Data\Llm;

class LlmUsage
{
    public function __construct(
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly ?int $totalTokens = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $usage
     */
    public static function fromProviderPayload(?array $usage): ?self
    {
        if ($usage === null) {
            return null;
        }

        $input = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
        $output = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
        $total = $usage['total_tokens'] ?? null;

        return new self(
            inputTokens: is_numeric($input) ? (int) $input : null,
            outputTokens: is_numeric($output) ? (int) $output : null,
            totalTokens: is_numeric($total) ? (int) $total : null,
        );
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
