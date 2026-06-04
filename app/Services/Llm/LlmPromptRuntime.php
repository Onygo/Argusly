<?php

namespace App\Services\Llm;

use App\Contracts\LlmClientInterface;
use App\Data\Llm\LlmRequest;
use App\Data\Llm\LlmResponse;
use App\Models\Account;
use App\Models\Brand;
use App\Models\User;
use App\Services\LlmResolver;

class LlmPromptRuntime
{
    public function __construct(
        private readonly LlmResolver $resolver,
        private readonly LlmClientInterface $client,
    ) {}

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array<string, mixed>  $metadata
     */
    public function generate(
        Account $account,
        ?Brand $brand,
        ?User $user,
        string $purpose,
        array $messages,
        ?string $systemPrompt = null,
        ?string $fakeContent = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        array $metadata = [],
    ): LlmResponse {
        $resolved = $this->resolver->resolve($account, $brand);

        return $this->client->generate(new LlmRequest(
            provider: $resolved['provider']['provider'],
            model: $resolved['model']['model'],
            messages: $messages,
            systemPrompt: $systemPrompt,
            temperature: $temperature ?? (is_numeric($resolved['temperature']) ? (float) $resolved['temperature'] : null),
            maxTokens: $maxTokens ?? (is_numeric($resolved['max_tokens']) ? (int) $resolved['max_tokens'] : null),
            metadata: [
                ...$metadata,
                'purpose' => $purpose,
                'account_id' => $account->id,
                'brand_id' => $brand?->id,
                'user_id' => $user?->id,
                'llm_source' => $resolved['source'],
                'fallback_provider' => $resolved['fallback_provider']['provider'] ?? null,
                'fallback_model' => $resolved['fallback_model']['model'] ?? null,
                'fake_content' => $fakeContent,
            ],
        ));
    }
}
