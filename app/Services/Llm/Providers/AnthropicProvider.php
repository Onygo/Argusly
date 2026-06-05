<?php

namespace App\Services\Llm\Providers;

use App\Contracts\LlmProvider;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\LlmJsonNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AnthropicProvider implements LlmProvider
{
    public function __construct(
        private readonly LlmJsonNormalizer $jsonNormalizer = new LlmJsonNormalizer(),
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function generateText(LlmRequest $request): LlmResponse
    {
        $cfg = (array) config('llm.providers.anthropic', []);
        $apiKey = (string) ($cfg['api_key'] ?? '');
        if ($apiKey === '') {
            throw new LlmException('ANTHROPIC_API_KEY is not set.', provider: $this->name(), userMessage: 'LLM provider is not configured.');
        }

        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://api.anthropic.com'), '/');
        $url = $baseUrl . '/v1/messages';

        $system = collect($request->messages)
            ->filter(fn ($m) => $m->role === 'system')
            ->map(fn ($m) => trim($m->content))
            ->filter()
            ->implode("\n\n");

        $messages = collect($request->messages)
            ->filter(fn ($m) => $m->role !== 'system')
            ->map(function ($m): array {
                $role = in_array($m->role, ['user', 'assistant'], true) ? $m->role : 'user';

                return [
                    'role' => $role,
                    'content' => $m->content,
                ];
            })
            ->values()
            ->all();

        if ($messages === []) {
            $messages[] = ['role' => 'user', 'content' => 'Return a concise answer.'];
        }

        $payload = [
            'model' => (string) $request->model,
            'max_tokens' => max(128, (int) ($request->maxTokens ?? 1800)),
            'messages' => $messages,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->topP !== null) {
            $payload['top_p'] = $request->topP;
        }

        if ($request->responseFormat === 'json') {
            $payload['messages'][] = [
                'role' => 'user',
                'content' => 'Return strict JSON only. No markdown, no prose.',
            ];
        }

        $attempts = max(1, (int) ($request->metadata['llm_retry_max'] ?? config('llm.retries.max_attempts', 2)));
        $backoff = max(100, (int) ($request->metadata['llm_retry_backoff_ms'] ?? config('llm.retries.base_backoff_ms', 800)));
        $connectTimeout = max(1, (int) config('llm.timeouts.connect_seconds', 10));
        $requestTimeout = max(15, (int) ($request->metadata['llm_timeout_seconds'] ?? config('llm.timeouts.request_seconds', 180)));

        $response = null;
        $attemptUsed = 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $attemptUsed = $attempt;
                $response = Http::connectTimeout($connectTimeout)
                    ->timeout($requestTimeout)
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => (string) ($cfg['version'] ?? '2023-06-01'),
                        'content-type' => 'application/json',
                    ])
                    ->acceptJson()
                    ->asJson()
                    ->post($url, $payload);

                $status = $response->status();
                if (in_array($status, [429, 500, 503], true) && $attempt < $attempts) {
                    usleep($backoff * $attempt * 1000);
                    continue;
                }

                break;
            } catch (ConnectionException $exception) {
                if ($attempt >= $attempts) {
                    throw new LlmException(
                        'Anthropic request failed: ' . $exception->getMessage(),
                        provider: $this->name(),
                        userMessage: 'The AI provider timed out. Please try again.'
                    );
                }

                usleep($backoff * $attempt * 1000);
            }
        }

        if (! $response || $response->failed()) {
            $status = $response?->status();
            $body = $response?->json();
            $requestId = (string) ($response?->header('request-id') ?: '');
            $message = data_get($body, 'error.message') ?: (string) $response?->body();

            throw new LlmException(
                message: 'Anthropic request failed' . ($status ? " ({$status})" : '') . ': ' . Str::limit((string) $message, 1200),
                statusCode: $status,
                provider: $this->name(),
                requestId: $requestId ?: null,
                userMessage: $this->mapUserMessage($status),
            );
        }

        $json = (array) $response->json();
        $json['_pl'] = ['retry_count' => max(0, $attemptUsed - 1)];
        $text = collect((array) Arr::get($json, 'content', []))
            ->filter(fn ($item) => (string) Arr::get($item, 'type') === 'text')
            ->map(fn ($item) => trim((string) Arr::get($item, 'text', '')))
            ->filter()
            ->implode("\n");

        $usage = (array) Arr::get($json, 'usage', []);
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));

        return new LlmResponse(
            text: trim($text),
            json: $request->responseFormat === 'json' ? $this->decodeJson($text) : null,
            usage: new LlmUsage($inputTokens, $outputTokens, $totalTokens),
            modelUsed: (string) (Arr::get($json, 'model') ?: $request->model),
            providerName: $this->name(),
            requestId: (string) ($response->header('request-id') ?: ''),
            raw: $json,
        );
    }

    public function generateJson(LlmRequest $request, array|string|null $schemaOrExpectation = null): LlmResponse
    {
        return $this->generateText(new LlmRequest(
            messages: $request->messages,
            model: $request->model,
            temperature: $request->temperature,
            maxTokens: $request->maxTokens,
            topP: $request->topP,
            responseFormat: 'json',
            metadata: $request->metadata,
        ));
    }

    private function decodeJson(string $text): ?array
    {
        return $this->jsonNormalizer->decode($text, $this->name());
    }

    private function mapUserMessage(?int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'Claude authentication failed. Check API credentials.',
            $status === 429 => 'Claude rate limit reached. Try again shortly.',
            $status === 400 || $status === 422 => 'The Claude request was invalid. Please review the input.',
            $status !== null && $status >= 500 => 'Claude is temporarily unavailable. Try again shortly.',
            default => 'AI generation failed. Please try again.',
        };
    }
}
