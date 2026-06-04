<?php

namespace App\Services\Llm\Clients;

use App\Contracts\LlmClientInterface;
use App\Data\Llm\LlmRequest;
use App\Data\Llm\LlmResponse;
use App\Data\Llm\LlmUsage;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Traversable;

class OpenAiLlmClient implements LlmClientInterface
{
    public function __construct(private readonly FakeLlmClient $fake) {}

    public function chat(LlmRequest $request): LlmResponse
    {
        if (! $this->hasApiKey()) {
            return $this->fake->chat($request);
        }

        $started = microtime(true);
        $response = Http::withToken((string) env('OPENAI_API_KEY'))
            ->timeout((int) env('OPENAI_TIMEOUT', 60))
            ->baseUrl(rtrim((string) config('llm.providers.openai.base_url'), '/'))
            ->post('/chat/completions', $this->payload($request));

        if ($response->failed()) {
            throw new RuntimeException('OpenAI LLM request failed: '.$response->body());
        }

        $payload = $response->json();
        $choice = $payload['choices'][0] ?? [];

        return new LlmResponse(
            provider: 'openai',
            model: $payload['model'] ?? $request->model,
            content: (string) data_get($choice, 'message.content', ''),
            rawResponse: is_array($payload) ? $payload : null,
            usage: LlmUsage::fromProviderPayload(is_array($payload['usage'] ?? null) ? $payload['usage'] : null),
            finishReason: is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
            latencyMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        return $this->chat($request);
    }

    public function stream(LlmRequest $request): Traversable
    {
        yield $this->chat($request)->content;
    }

    public function embed(LlmRequest $request): LlmResponse
    {
        return $this->fake->embed($request);
    }

    public function vision(LlmRequest $request): LlmResponse
    {
        return $this->chat($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(LlmRequest $request): array
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->messagesWithSystemPrompt(),
        ];

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }

        if ($request->responseFormat !== null) {
            $payload['response_format'] = is_string($request->responseFormat)
                ? ['type' => $request->responseFormat]
                : $request->responseFormat;
        }

        return $payload;
    }

    private function hasApiKey(): bool
    {
        if (app()->environment('testing') && config('llm.allow_testing_http') !== true) {
            return false;
        }

        return is_string(env('OPENAI_API_KEY')) && env('OPENAI_API_KEY') !== '';
    }
}
