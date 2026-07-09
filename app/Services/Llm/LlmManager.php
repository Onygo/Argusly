<?php

namespace App\Services\Llm;

use App\Contracts\LlmProvider;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\Providers\AnthropicProvider;
use App\Services\Llm\Providers\GeminiProvider;
use App\Services\Llm\Providers\MistralProvider;
use App\Services\Llm\Providers\OpenAiProvider;
use RuntimeException;

class LlmManager
{
    /** @var array<string, LlmProvider> */
    private array $drivers;

    public function __construct(
        private readonly LlmRoutingService $routing,
        private readonly LlmRequestLoggingService $logging,
    ) {
        $this->drivers = [
            'openai' => new OpenAiProvider(),
            'anthropic' => new AnthropicProvider(),
            'gemini' => new GeminiProvider(),
            'mistral' => new MistralProvider(),
        ];
    }

    public function driver(?string $name = null): LlmProvider
    {
        $resolved = $name ?: (string) config('llm.default_provider', 'openai');

        if (! isset($this->drivers[$resolved])) {
            throw new RuntimeException('Unsupported LLM provider: ' . $resolved);
        }

        return $this->drivers[$resolved];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function resolveProviderName(array $metadata = [], ?string $requestProvider = null): string
    {
        $route = $this->routing->resolve(
            feature: trim((string) ($metadata['feature'] ?? 'generic_text')),
            modality: trim((string) ($metadata['modality'] ?? 'text')) ?: 'text',
            workspaceId: $this->nullableString($metadata['workspaceId'] ?? null),
            siteId: $this->nullableString($metadata['siteId'] ?? null),
            requestedProvider: $this->nullableString($requestProvider ?: ($metadata['provider'] ?? null)),
            requestedModel: null,
        );

        return (string) ($route['provider'] ?: config('llm.default_provider', 'openai'));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function generateText(LlmRequest $request, array $metadata = []): LlmResponse
    {
        return $this->dispatch($request, 'text', null, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function generateJson(
        LlmRequest $request,
        array|string|null $schemaOrExpectation = null,
        array $metadata = []
    ): LlmResponse {
        $response = $this->dispatch($request, 'json', $schemaOrExpectation, $metadata);
        if ($response->json !== null) {
            return $response;
        }

        $retryEnabled = (bool) ($metadata['llm_json_fix_retry_enabled']
            ?? $request->metadata['llm_json_fix_retry_enabled']
            ?? config('llm.json.fix_retry_enabled', false));

        // Log when JSON decode failed for visibility
        \Illuminate\Support\Facades\Log::warning('LlmManager: JSON response was null after dispatch', [
            'feature' => $request->metadata['feature'] ?? 'unknown',
            'model' => $response->modelUsed,
            'provider' => $response->providerName,
            'request_id' => $response->requestId,
            'response_status' => data_get($response->raw, 'status'),
            'incomplete_reason' => data_get($response->raw, 'incomplete_details.reason'),
            'text_preview' => \Illuminate\Support\Str::limit($response->text ?? '', 300),
            'retry_enabled' => $retryEnabled,
        ]);

        if ((string) data_get($response->raw, 'status') === 'incomplete') {
            return $response;
        }

        if (! $retryEnabled) {
            return $response;
        }

        $fixHint = 'Your previous output was invalid JSON. Return strict JSON only, no markdown.';
        $messages = $request->messages;
        $messages[] = new LlmMessage('user', $fixHint);

        return $this->dispatch(
            new LlmRequest(
                messages: $messages,
                model: $request->model,
                temperature: $request->temperature,
                maxTokens: $request->maxTokens,
                topP: $request->topP,
                responseFormat: 'json',
                metadata: array_merge($request->metadata, [
                    'llm_json_fix_retry_attempted' => true,
                ]),
            ),
            'json',
            $schemaOrExpectation,
            $metadata,
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function dispatch(
        LlmRequest $request,
        string $responseMode,
        array|string|null $schemaOrExpectation,
        array $metadata
    ): LlmResponse {
        $mergedMeta = array_merge($request->metadata, $metadata);
        $feature = trim((string) ($mergedMeta['feature'] ?? 'generic_text'));
        $modality = trim((string) ($mergedMeta['modality'] ?? 'text')) ?: 'text';
        $workspaceId = $this->nullableString($mergedMeta['workspaceId'] ?? null);
        $siteId = $this->nullableString($mergedMeta['siteId'] ?? null);
        $userId = isset($mergedMeta['userId']) ? (int) $mergedMeta['userId'] : null;

        $route = $this->routing->resolve(
            feature: $feature,
            modality: $modality,
            workspaceId: $workspaceId,
            siteId: $siteId,
            requestedProvider: $this->nullableString($mergedMeta['provider'] ?? null),
            requestedModel: $this->nullableString($request->model),
        );
        $globalSettings = $this->routing->getGlobalSettings();

        $runtimeMeta = [
            'llm_retry_max' => $mergedMeta['llm_retry_max'] ?? (int) ($globalSettings['retry_max'] ?? config('llm.retries.max_attempts', 2)),
            'llm_retry_backoff_ms' => $mergedMeta['llm_retry_backoff_ms'] ?? (int) ($globalSettings['retry_backoff_ms'] ?? config('llm.retries.base_backoff_ms', 800)),
            'llm_timeout_seconds' => $mergedMeta['llm_timeout_seconds'] ?? (int) ($globalSettings['timeout_seconds'] ?? config('llm.timeouts.request_seconds', 180)),
        ];

        $resolvedRequest = new LlmRequest(
            messages: $request->messages,
            model: (string) ($route['model'] ?? ''),
            temperature: $request->temperature,
            maxTokens: $request->maxTokens,
            topP: $request->topP,
            responseFormat: $request->responseFormat,
            metadata: array_merge($mergedMeta, $runtimeMeta),
        );

        try {
            $started = microtime(true);
            $provider = (string) ($route['provider'] ?? config('llm.default_provider', 'openai'));
            $response = $responseMode === 'json'
                ? $this->driver($provider)->generateJson($resolvedRequest, $schemaOrExpectation)
                : $this->driver($provider)->generateText($resolvedRequest);
            $latencyMs = (int) round((microtime(true) - $started) * 1000);

            $this->logging->log($this->buildSuccessLogPayload(
                request: $resolvedRequest,
                response: $response,
                feature: $feature,
                modality: $modality,
                workspaceId: $workspaceId,
                siteId: $siteId,
                userId: $userId,
                latencyMs: $latencyMs,
                mergedMeta: $mergedMeta,
            ));

            return $response;
        } catch (\Throwable $primaryException) {
            $this->logging->log($this->buildErrorLogPayload(
                request: $resolvedRequest,
                provider: (string) ($route['provider'] ?? 'unknown'),
                model: (string) ($resolvedRequest->model ?? ''),
                feature: $feature,
                modality: $modality,
                workspaceId: $workspaceId,
                siteId: $siteId,
                userId: $userId,
                exception: $primaryException,
                mergedMeta: $mergedMeta,
            ));

            $fallbackRoute = $this->resolveFallbackRoute($primaryException, $route, $mergedMeta);
            if ($fallbackRoute === null) {
                throw $primaryException;
            }

            $fallbackProvider = (string) $fallbackRoute['provider'];
            $fallbackModel = (string) $fallbackRoute['model'];
            $fallbackRequest = new LlmRequest(
                messages: $request->messages,
                model: $fallbackModel,
                temperature: $request->temperature,
                maxTokens: $request->maxTokens,
                topP: $request->topP,
                responseFormat: $request->responseFormat,
                metadata: array_merge($mergedMeta, [
                    'fallback_from_provider' => (string) ($route['provider'] ?? ''),
                ], $runtimeMeta),
            );

            try {
                $started = microtime(true);
                $fallbackResponse = $responseMode === 'json'
                    ? $this->driver($fallbackProvider)->generateJson($fallbackRequest, $schemaOrExpectation)
                    : $this->driver($fallbackProvider)->generateText($fallbackRequest);
                $latencyMs = (int) round((microtime(true) - $started) * 1000);

                $this->logging->log($this->buildSuccessLogPayload(
                    request: $fallbackRequest,
                    response: $fallbackResponse,
                    feature: $feature,
                    modality: $modality,
                    workspaceId: $workspaceId,
                    siteId: $siteId,
                    userId: $userId,
                    latencyMs: $latencyMs,
                    mergedMeta: $fallbackRequest->metadata,
                ));

                return $fallbackResponse;
            } catch (\Throwable $fallbackException) {
                $this->logging->log($this->buildErrorLogPayload(
                    request: $fallbackRequest,
                    provider: $fallbackProvider,
                    model: $fallbackModel,
                    feature: $feature,
                    modality: $modality,
                    workspaceId: $workspaceId,
                    siteId: $siteId,
                    userId: $userId,
                    exception: $fallbackException,
                    mergedMeta: $fallbackRequest->metadata,
                ));

                throw $fallbackException;
            }
        }
    }

    /**
     * @param array<string,mixed> $route
     * @param array<string,mixed> $meta
     */
    private function resolveFallbackRoute(\Throwable $exception, array $route, array $meta): ?array
    {
        $primaryProvider = trim((string) ($route['provider'] ?? ''));

        if ($primaryProvider === '') {
            return null;
        }

        if (trim((string) ($meta['fallback_from_provider'] ?? '')) !== '') {
            return null;
        }

        $explicitFallbackEnabled = (bool) ($route['fallback_enabled'] ?? false);
        $explicitFallbackProvider = trim((string) ($route['fallback_provider'] ?? ''));
        $explicitFallbackModel = trim((string) ($route['fallback_model'] ?? ''));

        if ($explicitFallbackEnabled && $explicitFallbackProvider !== '') {
            if (! $this->isFallbackEligibleException($exception)) {
                return null;
            }

            if ($explicitFallbackProvider === $primaryProvider) {
                return null;
            }

            $model = $explicitFallbackModel !== ''
                ? $explicitFallbackModel
                : (string) config('llm.providers.' . $explicitFallbackProvider . '.default_model', '');

            return [
                'provider' => $explicitFallbackProvider,
                'model' => $model,
            ];
        }

        if (! (bool) config('llm.fallback.default_enabled', true)) {
            return null;
        }

        $defaultFallbackProvider = trim((string) config('llm.fallback.default_provider', 'openai'));
        if ($defaultFallbackProvider === '' || $defaultFallbackProvider === $primaryProvider) {
            return null;
        }

        if (! $this->isFallbackEligibleException($exception)) {
            return null;
        }

        $defaultModel = trim((string) config('llm.providers.' . $defaultFallbackProvider . '.default_model', ''));

        return [
            'provider' => $defaultFallbackProvider,
            'model' => $defaultModel,
        ];
    }

    private function isRetriableException(\Throwable $exception): bool
    {
        $statusCode = $exception instanceof LlmException ? (int) ($exception->statusCode ?? 0) : 0;
        if ($statusCode === 429 || $statusCode >= 500) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'connection')
            || str_contains($message, 'temporarily unavailable');
    }

    private function isFallbackEligibleException(\Throwable $exception): bool
    {
        if ($this->isRetriableException($exception)) {
            return true;
        }

        $statusCode = $exception instanceof LlmException ? (int) ($exception->statusCode ?? 0) : 0;
        if (in_array($statusCode, [400, 401, 403], true)) {
            $message = strtolower($exception->getMessage());

            return str_contains($message, 'quota')
                || str_contains($message, 'credit balance')
                || str_contains($message, 'insufficient')
                || str_contains($message, 'billing')
                || str_contains($message, 'authentication')
                || str_contains($message, 'unauthorized')
                || str_contains($message, 'forbidden');
        }

        return false;
    }

    /**
     * @param array<string,mixed> $mergedMeta
     * @return array<string,mixed>
     */
    private function buildSuccessLogPayload(
        LlmRequest $request,
        LlmResponse $response,
        string $feature,
        string $modality,
        ?string $workspaceId,
        ?string $siteId,
        ?int $userId,
        int $latencyMs,
        array $mergedMeta
    ): array {
        $promptCharsTotal = collect($request->messages)
            ->sum(fn (LlmMessage $message) => mb_strlen((string) $message->content));
        $promptMetrics = $this->promptMetrics($request->messages);

        return [
            'workspace_id' => $workspaceId,
            'site_id' => $siteId,
            'user_id' => $userId,
            'feature' => $feature,
            'modality' => $modality,
            'provider' => $response->providerName,
            'model' => $response->modelUsed,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'total_tokens' => $response->usage->totalTokens,
            'credits_consumed' => (float) ($mergedMeta['credits'] ?? 0),
            'latency_ms' => $latencyMs,
            'status' => 'success',
            'request_id' => $response->requestId,
            'job_id' => $this->nullableString($mergedMeta['jobId'] ?? null),
            'retry_count' => (int) data_get($response->raw, '_pl.retry_count', 0),
            'metadata' => [
                'message_count' => count($request->messages),
                'prompt_chars_total' => $promptCharsTotal,
                'prompt_hash' => $promptMetrics['prompt_hash'],
                'message_metrics' => $promptMetrics['messages'],
                'provider_raw' => $response->raw,
                'meta' => $this->safeMetaSubset($mergedMeta),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $mergedMeta
     * @return array<string,mixed>
     */
    private function buildErrorLogPayload(
        LlmRequest $request,
        string $provider,
        string $model,
        string $feature,
        string $modality,
        ?string $workspaceId,
        ?string $siteId,
        ?int $userId,
        \Throwable $exception,
        array $mergedMeta
    ): array {
        $promptCharsTotal = collect($request->messages)
            ->sum(fn (LlmMessage $message) => mb_strlen((string) $message->content));
        $promptMetrics = $this->promptMetrics($request->messages);

        $statusCode = $exception instanceof LlmException ? $exception->statusCode : null;
        $requestId = $exception instanceof LlmException ? $exception->requestId : null;

        return [
            'workspace_id' => $workspaceId,
            'site_id' => $siteId,
            'user_id' => $userId,
            'feature' => $feature,
            'modality' => $modality,
            'provider' => $provider,
            'model' => $model,
            'credits_consumed' => (float) ($mergedMeta['credits'] ?? 0),
            'status' => 'error',
            'error_type' => class_basename($exception),
            'error_message' => $exception->getMessage(),
            'error_code' => $statusCode ? (string) $statusCode : null,
            'request_id' => $requestId,
            'job_id' => $this->nullableString($mergedMeta['jobId'] ?? null),
            'metadata' => [
                'message_count' => count($request->messages),
                'prompt_chars_total' => $promptCharsTotal,
                'prompt_hash' => $promptMetrics['prompt_hash'],
                'message_metrics' => $promptMetrics['messages'],
                'meta' => $this->safeMetaSubset($mergedMeta),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function safeMetaSubset(array $meta): array
    {
        return [
            'workspaceId' => $meta['workspaceId'] ?? null,
            'siteId' => $meta['siteId'] ?? null,
            'contentId' => $meta['contentId'] ?? null,
            'draftId' => $meta['draftId'] ?? null,
            'queryId' => $meta['queryId'] ?? null,
            'source_draft_id' => $meta['source_draft_id'] ?? null,
            'source_language' => $meta['source_language'] ?? null,
            'target_language' => $meta['target_language'] ?? null,
            'sub_feature' => $meta['sub_feature'] ?? null,
            'prompt_version' => $meta['prompt_version'] ?? null,
            'eval_case_id' => $meta['eval_case_id'] ?? null,
            'eval_rubric_version' => $meta['eval_rubric_version'] ?? null,
            'schema_name' => $meta['schema_name'] ?? null,
            'context_strategy' => $meta['context_strategy'] ?? null,
            'trigger' => $meta['trigger'] ?? null,
            'fallback_from_provider' => $meta['fallback_from_provider'] ?? null,
            'llm_json_fix_retry_attempted' => $meta['llm_json_fix_retry_attempted'] ?? null,
        ];
    }

    /**
     * @param array<int, LlmMessage> $messages
     * @return array{prompt_hash:string,messages:array<int,array{role:string,chars:int,sha1:string}>}
     */
    private function promptMetrics(array $messages): array
    {
        $parts = [];
        $metrics = [];

        foreach ($messages as $message) {
            $content = (string) $message->content;
            $parts[] = $message->role . ':' . sha1($content);
            $metrics[] = [
                'role' => $message->role,
                'chars' => mb_strlen($content),
                'sha1' => sha1($content),
            ];
        }

        return [
            'prompt_hash' => sha1(implode('|', $parts)),
            'messages' => $metrics,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $candidate = trim((string) ($value ?? ''));

        return $candidate !== '' ? $candidate : null;
    }
}
