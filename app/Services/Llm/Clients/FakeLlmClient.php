<?php

namespace App\Services\Llm\Clients;

use App\Contracts\LlmClientInterface;
use App\Data\Llm\LlmRequest;
use App\Data\Llm\LlmResponse;
use App\Data\Llm\LlmUsage;
use Traversable;

class FakeLlmClient implements LlmClientInterface
{
    public function __construct(private readonly ?string $provider = null) {}

    public function chat(LlmRequest $request): LlmResponse
    {
        return $this->fakeResponse($request, 'chat');
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        return $this->fakeResponse($request, 'generate');
    }

    public function stream(LlmRequest $request): Traversable
    {
        yield $this->generate($request)->content;
    }

    public function embed(LlmRequest $request): LlmResponse
    {
        return $this->fakeResponse($request, 'embed');
    }

    public function vision(LlmRequest $request): LlmResponse
    {
        return $this->fakeResponse($request, 'vision');
    }

    private function fakeResponse(LlmRequest $request, string $mode): LlmResponse
    {
        $content = $request->metadata['fake_content'] ?? $this->contentFor($request, $mode);
        $inputTokens = strlen(json_encode($request->messagesWithSystemPrompt()) ?: '') > 0
            ? (int) ceil(strlen(json_encode($request->messagesWithSystemPrompt()) ?: '') / 4)
            : null;

        return new LlmResponse(
            provider: $this->provider ?? $request->provider,
            model: $request->model,
            content: (string) $content,
            rawResponse: [
                'fake' => true,
                'mode' => $mode,
                'metadata' => $request->metadata,
            ],
            usage: new LlmUsage(
                inputTokens: $inputTokens,
                outputTokens: (int) ceil(strlen((string) $content) / 4),
                totalTokens: $inputTokens === null ? null : $inputTokens + (int) ceil(strlen((string) $content) / 4),
            ),
            finishReason: 'stop',
            latencyMs: 0,
        );
    }

    private function contentFor(LlmRequest $request, string $mode): string
    {
        $lastMessage = collect($request->messages)->last();
        $lastContent = is_array($lastMessage) ? ($lastMessage['content'] ?? '') : '';
        $lastContent = is_string($lastContent) ? $lastContent : json_encode($lastContent);

        return trim(implode("\n\n", [
            "Fake {$mode} response from {$request->provider} using {$request->model}.",
            (string) $lastContent,
        ]));
    }
}
