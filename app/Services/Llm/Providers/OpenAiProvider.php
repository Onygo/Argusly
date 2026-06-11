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

class OpenAiProvider implements LlmProvider
{
    public function __construct(
        private readonly LlmJsonNormalizer $jsonNormalizer = new LlmJsonNormalizer(),
    ) {}

    public function name(): string
    {
        return 'openai';
    }

    public function generateText(LlmRequest $request): LlmResponse
    {
        return $this->generate($request);
    }

    public function generateJson(LlmRequest $request, array|string|null $schemaOrExpectation = null): LlmResponse
    {
        return $this->generate(new LlmRequest(
            messages: $request->messages,
            model: $request->model,
            temperature: $request->temperature,
            maxTokens: $request->maxTokens,
            topP: $request->topP,
            responseFormat: 'json',
            metadata: $request->metadata,
        ), $schemaOrExpectation);
    }

    private function generate(LlmRequest $request, array|string|null $schemaOrExpectation = null): LlmResponse
    {
        $cfg = (array) config('llm.providers.openai', []);
        $apiKey = (string) ($cfg['api_key'] ?? '');
        if ($apiKey === '') {
            throw new LlmException('OPENAI_API_KEY is not set.', provider: $this->name(), userMessage: 'LLM provider is not configured.');
        }

        $baseUrl = $this->normalizeBaseUrl((string) ($cfg['base_url'] ?? 'https://api.openai.com'));
        $url = $baseUrl . '/v1/responses';

        $payload = [
            'model' => (string) $request->model,
            'input' => collect($request->messages)->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
            ])->values()->all(),
        ];

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->maxTokens !== null) {
            $payload['max_output_tokens'] = $request->maxTokens;
        }
        if ($request->topP !== null) {
            $payload['top_p'] = $request->topP;
        }
        if ($request->responseFormat === 'json') {
            $payload['text'] = [
                'format' => $this->buildTextFormat($schemaOrExpectation),
            ];
        }

        $attempts = max(1, (int) ($request->metadata['llm_retry_max'] ?? config('llm.retries.max_attempts', 2)));
        $backoff = max(100, (int) ($request->metadata['llm_retry_backoff_ms'] ?? config('llm.retries.base_backoff_ms', 800)));
        $connectTimeout = max(1, (int) config('llm.timeouts.connect_seconds', 10));
        $requestTimeout = max(15, (int) ($request->metadata['llm_timeout_seconds'] ?? config('llm.timeouts.request_seconds', 180)));

        $headers = [];
        if (! empty($cfg['organization'])) {
            $headers['OpenAI-Organization'] = (string) $cfg['organization'];
        }
        if (! empty($cfg['project'])) {
            $headers['OpenAI-Project'] = (string) $cfg['project'];
        }

        $response = null;
        $attemptUsed = 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $attemptUsed = $attempt;
                $response = Http::connectTimeout($connectTimeout)
                    ->timeout($requestTimeout)
                    ->withToken($apiKey)
                    ->withHeaders($headers)
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
                        'OpenAI request failed: ' . $exception->getMessage(),
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
            $requestId = (string) ($response?->header('x-request-id') ?: data_get($body, 'id', '')) ?: null;
            $message = data_get($body, 'error.message') ?: (string) $response?->body();

            throw new LlmException(
                message: 'OpenAI request failed' . ($status ? " ({$status})" : '') . ': ' . Str::limit((string) $message, 1200),
                statusCode: $status,
                provider: $this->name(),
                requestId: $requestId,
                userMessage: $this->mapUserMessage($status),
            );
        }

        $json = (array) $response->json();
        $json['_pl'] = ['retry_count' => max(0, $attemptUsed - 1)];
        $text = $this->extractText($json);

        $usage = (array) Arr::get($json, 'usage', []);
        $inputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));

        return new LlmResponse(
            text: $text,
            json: $request->responseFormat === 'json' ? $this->decodeJson($text) : null,
            usage: new LlmUsage($inputTokens, $outputTokens, $totalTokens),
            modelUsed: (string) (Arr::get($json, 'model') ?: $request->model),
            providerName: $this->name(),
            requestId: (string) ($response->header('x-request-id') ?: Arr::get($json, 'id', '')) ?: null,
            raw: $json,
        );
    }

    private function extractText(array $data): string
    {
        $direct = trim((string) Arr::get($data, 'output_text', ''));
        if ($direct !== '') {
            return $direct;
        }

        $parts = [];
        foreach ((array) Arr::get($data, 'output', []) as $item) {
            foreach ((array) Arr::get($item, 'content', []) as $content) {
                $text = trim((string) Arr::get($content, 'text', ''));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    private function decodeJson(string $text): ?array
    {
        return $this->jsonNormalizer->decode($text, $this->name());
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        return Str::of($baseUrl)->lower()->endsWith('/v1')
            ? (string) Str::of($baseUrl)->substr(0, -3)
            : $baseUrl;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildTextFormat(array|string|null $schemaOrExpectation): array
    {
        $schema = $this->resolveJsonSchema($schemaOrExpectation);
        if ($schema !== null) {
            return array_merge(['type' => 'json_schema'], $schema);
        }

        return ['type' => 'json_object'];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveJsonSchema(array|string|null $schemaOrExpectation): ?array
    {
        if (! is_array($schemaOrExpectation) || $schemaOrExpectation === []) {
            return null;
        }

        if ((string) ($schemaOrExpectation['type'] ?? '') === 'json_schema' && is_array($schemaOrExpectation['json_schema'] ?? null)) {
            return $this->normalizeJsonSchemaPayload((array) $schemaOrExpectation['json_schema']);
        }

        if (is_array($schemaOrExpectation['json_schema'] ?? null)) {
            return $this->normalizeJsonSchemaPayload((array) $schemaOrExpectation['json_schema']);
        }

        if (is_array($schemaOrExpectation['schema'] ?? null)) {
            return $this->normalizeJsonSchemaPayload([
                'name' => (string) ($schemaOrExpectation['name'] ?? 'argusly_response'),
                'description' => (string) ($schemaOrExpectation['description'] ?? ''),
                'schema' => $schemaOrExpectation['schema'],
                'strict' => (bool) ($schemaOrExpectation['strict'] ?? true),
            ]);
        }

        if (array_key_exists('type', $schemaOrExpectation) || array_key_exists('properties', $schemaOrExpectation)) {
            return $this->normalizeJsonSchemaPayload([
                'name' => 'argusly_response',
                'schema' => $schemaOrExpectation,
                'strict' => true,
            ]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $jsonSchema
     * @return array<string,mixed>
     */
    private function normalizeJsonSchemaPayload(array $jsonSchema): array
    {
        $name = trim((string) ($jsonSchema['name'] ?? 'argusly_response'));
        $schema = is_array($jsonSchema['schema'] ?? null) ? $jsonSchema['schema'] : $jsonSchema;
        $payload = [
            'name' => $name !== '' ? $name : 'argusly_response',
            'schema' => $schema,
            'strict' => (bool) ($jsonSchema['strict'] ?? true),
        ];

        $description = trim((string) ($jsonSchema['description'] ?? ''));
        if ($description !== '') {
            $payload['description'] = $description;
        }

        return $payload;
    }

    private function mapUserMessage(?int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'OpenAI authentication failed. Check API credentials.',
            $status === 429 => 'OpenAI rate limit reached. Try again shortly.',
            $status === 400 || $status === 422 => 'The AI request was invalid. Please review the input.',
            $status !== null && $status >= 500 => 'OpenAI is temporarily unavailable. Try again shortly.',
            default => 'AI generation failed. Please try again.',
        };
    }
}
