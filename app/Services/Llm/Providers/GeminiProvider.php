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

class GeminiProvider implements LlmProvider
{
    public function __construct(
        private readonly LlmJsonNormalizer $jsonNormalizer = new LlmJsonNormalizer(),
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function generateText(LlmRequest $request): LlmResponse
    {
        $cfg = (array) config('llm.providers.gemini', []);
        $apiKey = (string) ($cfg['api_key'] ?? '');
        if ($apiKey === '') {
            throw new LlmException('GEMINI_API_KEY is not set.', provider: $this->name(), userMessage: 'LLM provider is not configured.');
        }

        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $model = trim((string) $request->model);
        if ($model === '') {
            throw new LlmException('Gemini model is required.', provider: $this->name(), userMessage: 'No Gemini model configured.');
        }

        $modelPath = str_starts_with($model, 'models/') ? $model : 'models/' . $model;
        $url = $baseUrl . '/' . $modelPath . ':generateContent?key=' . urlencode($apiKey);

        $system = collect($request->messages)
            ->filter(fn ($m) => $m->role === 'system')
            ->map(fn ($m) => trim($m->content))
            ->filter()
            ->implode("\n\n");

        $contents = collect($request->messages)
            ->filter(fn ($m) => $m->role !== 'system')
            ->map(function ($m): array {
                $role = $m->role === 'assistant' ? 'model' : 'user';

                return [
                    'role' => $role,
                    'parts' => [
                        ['text' => $m->content],
                    ],
                ];
            })
            ->values()
            ->all();

        if ($contents === []) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => 'Return a concise answer.']]];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => array_filter([
                'temperature' => $request->temperature,
                'topP' => $request->topP,
                'maxOutputTokens' => $request->maxTokens,
                'responseMimeType' => $request->responseFormat === 'json' ? 'application/json' : null,
            ], fn ($value) => $value !== null),
        ];

        if ($system !== '') {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $system],
                ],
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
                        'Gemini request failed: ' . $exception->getMessage(),
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
            $message = data_get($body, 'error.message') ?: (string) $response?->body();

            throw new LlmException(
                message: 'Gemini request failed' . ($status ? " ({$status})" : '') . ': ' . Str::limit((string) $message, 1200),
                statusCode: $status,
                provider: $this->name(),
                requestId: (string) data_get($body, 'responseId', ''),
                userMessage: $this->mapUserMessage($status),
            );
        }

        $json = (array) $response->json();
        $json['_pl'] = ['retry_count' => max(0, $attemptUsed - 1)];
        $text = collect((array) Arr::get($json, 'candidates.0.content.parts', []))
            ->map(fn ($part) => trim((string) Arr::get($part, 'text', '')))
            ->filter()
            ->implode("\n");

        $usage = (array) Arr::get($json, 'usageMetadata', []);
        $inputTokens = (int) ($usage['promptTokenCount'] ?? 0);
        $outputTokens = (int) ($usage['candidatesTokenCount'] ?? 0);
        $totalTokens = (int) ($usage['totalTokenCount'] ?? ($inputTokens + $outputTokens));

        return new LlmResponse(
            text: trim($text),
            json: $request->responseFormat === 'json' ? $this->decodeJson($text) : null,
            usage: new LlmUsage($inputTokens, $outputTokens, $totalTokens),
            modelUsed: (string) (Arr::get($json, 'modelVersion') ?: $request->model),
            providerName: $this->name(),
            requestId: (string) Arr::get($json, 'responseId', ''),
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
            $status === 401 || $status === 403 => 'Gemini authentication failed. Check API credentials.',
            $status === 429 => 'Gemini rate limit reached. Try again shortly.',
            $status === 400 || $status === 422 => 'The Gemini request was invalid. Please review the input.',
            $status !== null && $status >= 500 => 'Gemini is temporarily unavailable. Try again shortly.',
            default => 'AI generation failed. Please try again.',
        };
    }
}
