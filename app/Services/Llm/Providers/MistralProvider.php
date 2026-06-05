<?php

namespace App\Services\Llm\Providers;

use App\Contracts\LlmProvider;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\LlmJsonNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MistralProvider implements LlmProvider
{
    public function __construct(
        private readonly LlmJsonNormalizer $jsonNormalizer = new LlmJsonNormalizer(),
    ) {}

    public function name(): string
    {
        return 'mistral';
    }

    public function generateText(LlmRequest $request): LlmResponse
    {
        return $this->send($request);
    }

    public function generateJson(LlmRequest $request, array|string|null $schemaOrExpectation = null): LlmResponse
    {
        return $this->send(new LlmRequest(
            messages: $request->messages,
            model: $request->model,
            temperature: $request->temperature,
            maxTokens: $request->maxTokens,
            topP: $request->topP,
            responseFormat: 'json',
            metadata: $request->metadata,
        ), $schemaOrExpectation);
    }

    /**
     * @return array<int,string>
     */
    public function listModels(): array
    {
        $cfg = (array) config('llm.providers.mistral', []);
        $apiKey = trim((string) ($cfg['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new LlmException('MISTRAL_API_KEY is not set.', provider: $this->name(), userMessage: 'LLM provider is not configured.');
        }

        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://api.mistral.ai/v1'), '/');
        $cacheKey = 'llm:mistral:models:' . sha1($baseUrl);

        return Cache::remember($cacheKey, now()->addMinutes(5), fn (): array => $this->fetchModels($apiKey, $baseUrl));
    }

    private function send(LlmRequest $request, array|string|null $schemaOrExpectation = null): LlmResponse
    {
        $cfg = (array) config('llm.providers.mistral', []);
        $apiKey = trim((string) ($cfg['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new LlmException('MISTRAL_API_KEY is not set.', provider: $this->name(), userMessage: 'LLM provider is not configured.');
        }

        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://api.mistral.ai/v1'), '/');
        $model = trim((string) ($request->model ?: ($cfg['default_model'] ?? '')));
        if ($model === '') {
            throw new LlmException('Mistral model is required.', provider: $this->name(), userMessage: 'No Mistral model configured.');
        }

        $url = $baseUrl . '/chat/completions';
        $payload = [
            'model' => $model,
            'messages' => $this->buildMessages($request, $schemaOrExpectation),
        ];

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->maxTokens !== null) {
            $payload['max_tokens'] = $request->maxTokens;
        }
        if ($request->topP !== null) {
            $payload['top_p'] = $request->topP;
        }

        $this->applyMetadataPayloadOptions($payload, $request->metadata);

        $responseFormat = $this->buildResponseFormat($request, $schemaOrExpectation);
        if ($responseFormat !== null) {
            $payload['response_format'] = $responseFormat;
        }

        $attempts = max(1, (int) ($request->metadata['llm_retry_max'] ?? config('llm.retries.max_attempts', 2)));
        $backoff = max(100, (int) ($request->metadata['llm_retry_backoff_ms'] ?? config('llm.retries.base_backoff_ms', 800)));
        $connectTimeout = max(1, (int) config('llm.timeouts.connect_seconds', 10));
        $requestTimeout = max(15, (int) ($request->metadata['llm_timeout_seconds'] ?? config('llm.timeouts.request_seconds', 180)));

        $response = null;
        $attemptUsed = 1;
        $streamRequested = (bool) ($payload['stream'] ?? false);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $attemptUsed = $attempt;
                $response = Http::connectTimeout($connectTimeout)
                    ->timeout($requestTimeout)
                    ->withToken($apiKey)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => $streamRequested ? 'text/event-stream' : 'application/json',
                    ])
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
                        'Mistral request failed: ' . $exception->getMessage(),
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
            $requestId = $response ? $this->extractRequestId($response, is_array($body) ? $body : []) : null;
            $message = data_get($body, 'error.message')
                ?: data_get($body, 'message')
                ?: (string) $response?->body();

            throw new LlmException(
                message: 'Mistral request failed' . ($status ? " ({$status})" : '') . ': ' . Str::limit((string) $message, 1200),
                statusCode: $status,
                provider: $this->name(),
                requestId: $requestId,
                userMessage: $this->mapUserMessage($status),
            );
        }

        $contentType = strtolower((string) $response->header('Content-Type', ''));
        $json = $streamRequested && str_contains($contentType, 'text/event-stream')
            ? $this->parseSseResponse((string) $response->body())
            : (array) $response->json();

        $json['_pl'] = [
            'retry_count' => max(0, $attemptUsed - 1),
            'stream' => $streamRequested,
        ];

        $text = $this->extractText($json);
        $usage = (array) Arr::get($json, 'usage', []);
        $inputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));

        return new LlmResponse(
            text: trim($text),
            json: $request->responseFormat === 'json' ? $this->decodeJson($text) : null,
            usage: new LlmUsage($inputTokens, $outputTokens, $totalTokens),
            modelUsed: (string) (Arr::get($json, 'model') ?: $model),
            providerName: $this->name(),
            requestId: $this->extractRequestId($response, $json),
            raw: $json,
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $metadata
     */
    private function applyMetadataPayloadOptions(array &$payload, array $metadata): void
    {
        $stop = $metadata['stop'] ?? null;
        if (is_string($stop) && trim($stop) !== '') {
            $payload['stop'] = trim($stop);
        } elseif (is_array($stop)) {
            $stopValues = collect($stop)
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->values()
                ->all();

            if ($stopValues !== []) {
                $payload['stop'] = $stopValues;
            }
        }

        if (array_key_exists('stream', $metadata)) {
            $payload['stream'] = (bool) $metadata['stream'];
        }

        if (array_key_exists('safe_prompt', $metadata)) {
            $payload['safe_prompt'] = (bool) $metadata['safe_prompt'];
        }

        if (array_key_exists('random_seed', $metadata) && is_numeric($metadata['random_seed'])) {
            $payload['random_seed'] = (int) $metadata['random_seed'];
        }

        if (isset($metadata['tools']) && is_array($metadata['tools']) && $metadata['tools'] !== []) {
            $payload['tools'] = $metadata['tools'];
        }

        if (array_key_exists('tool_choice', $metadata) && (is_string($metadata['tool_choice']) || is_array($metadata['tool_choice']))) {
            $payload['tool_choice'] = $metadata['tool_choice'];
        }

        if (array_key_exists('parallel_tool_calls', $metadata)) {
            $payload['parallel_tool_calls'] = (bool) $metadata['parallel_tool_calls'];
        }
    }

    /**
     * @return array<int,array{role:string,content:string}>
     */
    private function buildMessages(LlmRequest $request, array|string|null $schemaOrExpectation = null): array
    {
        $messages = collect($request->messages)
            ->map(function (LlmMessage $message): array {
                $role = in_array($message->role, ['system', 'user', 'assistant', 'tool'], true)
                    ? $message->role
                    : 'user';

                return [
                    'role' => $role,
                    'content' => $message->content,
                ];
            })
            ->values()
            ->all();

        if ($messages === []) {
            $messages[] = ['role' => 'user', 'content' => 'Return a concise answer.'];
        }

        if ($request->responseFormat === 'json') {
            $schemaRequested = $this->resolveJsonSchema($schemaOrExpectation) !== null;
            array_unshift($messages, [
                'role' => 'system',
                'content' => $schemaRequested
                    ? 'Return strict JSON only and ensure it matches the provided JSON schema. No markdown, no prose.'
                    : 'Return strict JSON only. No markdown, no prose.',
            ]);
        }

        return $messages;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildResponseFormat(LlmRequest $request, array|string|null $schemaOrExpectation): ?array
    {
        if ($request->responseFormat !== 'json') {
            return null;
        }

        $schema = $this->resolveJsonSchema($schemaOrExpectation);
        if ($schema !== null) {
            return [
                'type' => 'json_schema',
                'json_schema' => $schema,
            ];
        }

        return [
            'type' => 'json_object',
        ];
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
            return $this->normalizeJsonSchemaPayload($schemaOrExpectation['json_schema']);
        }

        if (is_array($schemaOrExpectation['json_schema'] ?? null)) {
            return $this->normalizeJsonSchemaPayload($schemaOrExpectation['json_schema']);
        }

        if (is_array($schemaOrExpectation['schema'] ?? null)) {
            return $this->normalizeJsonSchemaPayload([
                'name' => (string) ($schemaOrExpectation['name'] ?? 'publishlayer_response'),
                'description' => (string) ($schemaOrExpectation['description'] ?? ''),
                'schema' => $schemaOrExpectation['schema'],
                'strict' => (bool) ($schemaOrExpectation['strict'] ?? true),
            ]);
        }

        if (array_key_exists('type', $schemaOrExpectation) || array_key_exists('properties', $schemaOrExpectation)) {
            return $this->normalizeJsonSchemaPayload([
                'name' => 'publishlayer_response',
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
        $name = trim((string) ($jsonSchema['name'] ?? 'publishlayer_response'));
        $schema = is_array($jsonSchema['schema'] ?? null) ? $jsonSchema['schema'] : $jsonSchema;
        $payload = [
            'name' => $name !== '' ? $name : 'publishlayer_response',
            'schema' => $schema,
            'strict' => (bool) ($jsonSchema['strict'] ?? true),
        ];

        $description = trim((string) ($jsonSchema['description'] ?? ''));
        if ($description !== '') {
            $payload['description'] = $description;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseSseResponse(string $body): array
    {
        $id = '';
        $model = '';
        $usage = [];
        $finishReason = null;
        $parts = [];

        $events = preg_split('/\R\R+/', trim($body)) ?: [];

        foreach ($events as $event) {
            $dataLines = [];
            foreach (preg_split('/\R/', $event) ?: [] as $line) {
                $line = trim((string) $line);
                if (str_starts_with($line, 'data:')) {
                    $dataLines[] = ltrim(substr($line, 5));
                }
            }

            if ($dataLines === []) {
                continue;
            }

            $data = trim(implode("\n", $dataLines));
            if ($data === '' || $data === '[DONE]') {
                if ($data === '[DONE]') {
                    break;
                }

                continue;
            }

            $decoded = json_decode($data, true);
            if (! is_array($decoded)) {
                continue;
            }

            if ($id === '') {
                $id = (string) ($decoded['id'] ?? '');
            }
            if ($model === '') {
                $model = (string) ($decoded['model'] ?? '');
            }
            if (is_array($decoded['usage'] ?? null)) {
                $usage = $decoded['usage'];
            }

            foreach ((array) ($decoded['choices'] ?? []) as $choice) {
                $deltaContent = data_get($choice, 'delta.content');
                if (is_string($deltaContent) && $deltaContent !== '') {
                    $parts[] = $deltaContent;
                } elseif (is_array($deltaContent)) {
                    foreach ($deltaContent as $item) {
                        if (is_string($item) && $item !== '') {
                            $parts[] = $item;
                            continue;
                        }

                        $text = trim((string) data_get($item, 'text', data_get($item, 'content', '')));
                        if ($text !== '') {
                            $parts[] = $text;
                        }
                    }
                }

                $messageContent = data_get($choice, 'message.content');
                if (is_string($messageContent) && $messageContent !== '') {
                    $parts[] = $messageContent;
                }

                $finish = data_get($choice, 'finish_reason');
                if (is_string($finish) && $finish !== '') {
                    $finishReason = $finish;
                }
            }
        }

        return [
            'id' => $id,
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'finish_reason' => $finishReason,
                    'message' => [
                        'role' => 'assistant',
                        'content' => trim(implode('', $parts)),
                    ],
                ],
            ],
            'usage' => $usage,
            'object' => 'chat.completion',
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractText(array $data): string
    {
        $messageContent = Arr::get($data, 'choices.0.message.content');
        if (is_string($messageContent) && trim($messageContent) !== '') {
            return trim($messageContent);
        }

        if (is_array($messageContent)) {
            $parts = [];
            foreach ($messageContent as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $parts[] = trim($item);
                    continue;
                }

                $text = trim((string) data_get($item, 'text', data_get($item, 'content', '')));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            if ($parts !== []) {
                return implode("\n", $parts);
            }
        }

        $deltaContent = Arr::get($data, 'choices.0.delta.content');
        if (is_string($deltaContent) && trim($deltaContent) !== '') {
            return trim($deltaContent);
        }

        return '';
    }

    /**
     * @param array<string,mixed> $json
     */
    private function extractRequestId(Response $response, array $json): ?string
    {
        $id = trim((string) (
            $response->header('x-request-id')
            ?: $response->header('request-id')
            ?: ($json['id'] ?? '')
        ));

        return $id !== '' ? $id : null;
    }

    private function decodeJson(string $text): ?array
    {
        return $this->jsonNormalizer->decode($text, $this->name());
    }

    /**
     * @return array<int,string>
     */
    private function fetchModels(string $apiKey, string $baseUrl): array
    {
        $url = $baseUrl . '/models';
        $attempts = max(1, (int) config('llm.retries.max_attempts', 2));
        $backoff = max(100, (int) config('llm.retries.base_backoff_ms', 800));
        $connectTimeout = max(1, (int) config('llm.timeouts.connect_seconds', 10));
        $requestTimeout = max(15, (int) config('llm.timeouts.request_seconds', 180));

        $response = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::connectTimeout($connectTimeout)
                    ->timeout($requestTimeout)
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->get($url);

                $status = $response->status();
                if (in_array($status, [429, 500, 503], true) && $attempt < $attempts) {
                    usleep($backoff * $attempt * 1000);
                    continue;
                }

                break;
            } catch (ConnectionException $exception) {
                if ($attempt >= $attempts) {
                    throw new LlmException(
                        'Mistral model listing failed: ' . $exception->getMessage(),
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
            $message = data_get($body, 'error.message') ?: data_get($body, 'message') ?: (string) $response?->body();

            throw new LlmException(
                message: 'Mistral model listing failed' . ($status ? " ({$status})" : '') . ': ' . Str::limit((string) $message, 1200),
                statusCode: $status,
                provider: $this->name(),
                requestId: $response ? $this->extractRequestId($response, is_array($body) ? $body : []) : null,
                userMessage: $this->mapUserMessage($status),
            );
        }

        $json = $response->json();
        $rows = is_array($json) && array_is_list($json)
            ? $json
            : (array) data_get($json, 'data', []);

        return collect($rows)
            ->map(fn ($model) => trim((string) data_get($model, 'id', '')))
            ->filter()
            ->values()
            ->all();
    }

    private function mapUserMessage(?int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'Mistral authentication failed. Check API credentials.',
            $status === 429 => 'Mistral rate limit reached. Try again shortly.',
            $status === 400 || $status === 422 => 'The Mistral request was invalid. Please review the input.',
            $status !== null && $status >= 500 => 'Mistral is temporarily unavailable. Try again shortly.',
            default => 'AI generation failed. Please try again.',
        };
    }
}
