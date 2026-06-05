<?php

namespace App\Services\Llm;

use App\Contracts\LlmClientInterface;
use App\Data\Llm\LlmRequest;
use App\Data\Llm\LlmResponse;
use App\Services\Llm\Clients\AnthropicLlmClient;
use App\Services\Llm\Clients\FakeLlmClient;
use App\Services\Llm\Clients\GoogleLlmClient;
use App\Services\Llm\Clients\GroqLlmClient;
use App\Services\Llm\Clients\MistralLlmClient;
use App\Services\Llm\Clients\OpenAiLlmClient;
use App\Services\Llm\Clients\OpenRouterLlmClient;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Traversable;

class LlmClientManager implements LlmClientInterface
{
    public function __construct(
        private readonly OpenAiLlmClient $openai,
        private readonly AnthropicLlmClient $anthropic,
        private readonly GoogleLlmClient $google,
        private readonly MistralLlmClient $mistral,
        private readonly GroqLlmClient $groq,
        private readonly OpenRouterLlmClient $openrouter,
        private readonly FakeLlmClient $fake,
        private readonly LlmRequestTracker $tracker,
        private readonly LlmRuntimeRouter $router,
        private readonly LlmRuntimeGuard $guard,
    ) {}

    public function chat(LlmRequest $request): LlmResponse
    {
        return $this->tracked($request, 'chat', fn (LlmClientInterface $client, LlmRequest $trackedRequest) => $client->chat($trackedRequest));
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        return $this->tracked($request, 'generate', fn (LlmClientInterface $client, LlmRequest $trackedRequest) => $client->generate($trackedRequest));
    }

    public function stream(LlmRequest $request): Traversable
    {
        return $this->trackedStream($request);
    }

    public function embed(LlmRequest $request): LlmResponse
    {
        return $this->tracked($request, 'embed', fn (LlmClientInterface $client, LlmRequest $trackedRequest) => $client->embed($trackedRequest));
    }

    public function vision(LlmRequest $request): LlmResponse
    {
        return $this->tracked($request, 'vision', fn (LlmClientInterface $client, LlmRequest $trackedRequest) => $client->vision($trackedRequest));
    }

    private function clientFor(string $provider): LlmClientInterface
    {
        return match ($provider) {
            'openai' => $this->openai,
            'anthropic' => $this->anthropic,
            'google' => $this->google,
            'mistral' => $this->mistral,
            'groq' => $this->groq,
            'openrouter' => $this->openrouter,
            'fake', 'argusly_fake' => $this->fake,
            default => throw new InvalidArgumentException("Unsupported LLM runtime provider [{$provider}]."),
        };
    }

    /**
     * @param  callable(LlmClientInterface, LlmRequest): LlmResponse  $callback
     */
    private function tracked(LlmRequest $request, string $method, callable $callback): LlmResponse
    {
        $request = $this->router->route($request, $this->typeForMethod($method));
        $record = $this->tracker->start($request, $method);

        try {
            $this->guard->ensureAllowed($request);
            $response = $callback($this->clientFor($request->provider), $request);
            $this->validateStructuredOutput($request, $response);
            $this->tracker->complete($record, $response);

            return $response;
        } catch (Throwable $error) {
            $this->tracker->fail($record, $error);

            $fallback = $this->fallbackFor($request);

            if ($fallback === null) {
                throw $error;
            }

            $fallbackRequest = $this->tracker->fallbackRequest($request, $fallback['provider'], $fallback['model'], $record);
            $fallbackRecord = $this->tracker->start($fallbackRequest, $method);

            try {
                $this->guard->ensureAllowed($fallbackRequest);
                $response = $callback($this->clientFor($fallbackRequest->provider), $fallbackRequest);
                $this->validateStructuredOutput($fallbackRequest, $response);
                $this->tracker->complete($fallbackRecord, $response);

                return $response;
            } catch (Throwable $fallbackError) {
                $this->tracker->fail($fallbackRecord, $fallbackError);

                throw $fallbackError;
            }
        }
    }

    private function trackedStream(LlmRequest $request): Traversable
    {
        $request = $this->router->route($request, 'chat');
        $record = $this->tracker->start($request, 'stream');
        $content = '';

        try {
            $this->guard->ensureAllowed($request);
            foreach ($this->clientFor($request->provider)->stream($request) as $chunk) {
                $content .= $chunk;

                yield $chunk;
            }

            $this->tracker->complete($record, new LlmResponse(
                provider: $request->provider,
                model: $request->model,
                content: $content,
                rawResponse: ['streamed' => true],
            ));
        } catch (Throwable $error) {
            $this->tracker->fail($record, $error);

            $fallback = $this->fallbackFor($request);

            if ($fallback === null) {
                throw $error;
            }

            $fallbackRequest = $this->tracker->fallbackRequest($request, $fallback['provider'], $fallback['model'], $record);
            $fallbackRecord = $this->tracker->start($fallbackRequest, 'stream');
            $fallbackContent = '';

            try {
                $this->guard->ensureAllowed($fallbackRequest);
                foreach ($this->clientFor($fallbackRequest->provider)->stream($fallbackRequest) as $chunk) {
                    $fallbackContent .= $chunk;

                    yield $chunk;
                }

                $this->tracker->complete($fallbackRecord, new LlmResponse(
                    provider: $fallbackRequest->provider,
                    model: $fallbackRequest->model,
                    content: $fallbackContent,
                    rawResponse: ['streamed' => true],
                ));
            } catch (Throwable $fallbackError) {
                $this->tracker->fail($fallbackRecord, $fallbackError);

                throw $fallbackError;
            }
        }
    }

    /**
     * @return array{provider: string, model: string}|null
     */
    private function fallbackFor(LlmRequest $request): ?array
    {
        $provider = $request->metadata['fallback_provider'] ?? null;
        $model = $request->metadata['fallback_model'] ?? null;

        if (! is_string($provider) || $provider === '' || ! is_string($model) || $model === '') {
            return null;
        }

        if ($provider === $request->provider && $model === $request->model) {
            return null;
        }

        return ['provider' => $provider, 'model' => $model];
    }

    private function typeForMethod(string $method): string
    {
        return match ($method) {
            'embed' => 'embedding',
            'vision' => 'vision',
            default => 'chat',
        };
    }

    private function validateStructuredOutput(LlmRequest $request, LlmResponse $response): void
    {
        $format = $request->responseFormat;
        $expectsJson = $format === 'json_object'
            || (is_array($format) && in_array($format['type'] ?? null, ['json_object', 'json_schema'], true));

        if (! $expectsJson) {
            return;
        }

        json_decode($response->content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('LLM structured output validation failed: '.json_last_error_msg());
        }
    }
}
