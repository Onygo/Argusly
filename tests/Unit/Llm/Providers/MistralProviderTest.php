<?php

use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\Providers\MistralProvider;
use Illuminate\Support\Facades\Http;

it('maps schema mode to mistral json_schema response format', function () {
    config([
        'llm.providers.mistral.api_key' => 'test-mistral-key',
        'llm.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
        'llm.providers.mistral.default_model' => 'mistral-large-latest',
    ]);

    Http::fake([
        'https://api.mistral.ai/v1/chat/completions' => Http::response([
            'id' => 'cmpl_schema_1',
            'model' => 'mistral-large-latest',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"ok":true}',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 4,
                'total_tokens' => 14,
            ],
        ]),
    ]);

    $provider = new MistralProvider();
    $provider->generateJson(new LlmRequest(
        messages: [new LlmMessage('user', 'Return JSON')],
        model: 'mistral-large-latest',
    ), [
        'name' => 'argusly_response',
        'schema' => [
            'type' => 'object',
            'properties' => [
                'ok' => ['type' => 'boolean'],
            ],
            'required' => ['ok'],
            'additionalProperties' => false,
        ],
        'strict' => true,
    ]);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ($data['response_format']['type'] ?? '') === 'json_schema'
            && ($data['response_format']['json_schema']['name'] ?? '') === 'argusly_response'
            && is_array($data['response_format']['json_schema']['schema'] ?? null)
            && ($data['response_format']['json_schema']['strict'] ?? null) === true;
    });
});

it('parses mistral streaming sse responses into assistant text', function () {
    config([
        'llm.providers.mistral.api_key' => 'test-mistral-key',
        'llm.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
        'llm.providers.mistral.default_model' => 'mistral-large-latest',
    ]);

    $sse = implode("\n\n", [
        'data: {"id":"cmpl_stream_1","model":"mistral-large-latest","choices":[{"delta":{"content":"Hello"},"finish_reason":null}]}',
        'data: {"id":"cmpl_stream_1","model":"mistral-large-latest","choices":[{"delta":{"content":" world"},"finish_reason":null}]}',
        'data: {"id":"cmpl_stream_1","model":"mistral-large-latest","choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":9,"completion_tokens":2,"total_tokens":11}}',
        'data: [DONE]',
    ]) . "\n";

    Http::fake([
        'https://api.mistral.ai/v1/chat/completions' => Http::response(
            $sse,
            200,
            ['Content-Type' => 'text/event-stream', 'x-request-id' => 'req_stream_1']
        ),
    ]);

    $provider = new MistralProvider();
    $response = $provider->generateText(new LlmRequest(
        messages: [new LlmMessage('user', 'Say hello')],
        model: 'mistral-large-latest',
        metadata: ['stream' => true],
    ));

    expect($response->text)->toBe('Hello world')
        ->and($response->usage->inputTokens)->toBe(9)
        ->and($response->usage->outputTokens)->toBe(2)
        ->and($response->usage->totalTokens)->toBe(11)
        ->and($response->requestId)->toBe('req_stream_1');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return ($data['stream'] ?? null) === true;
    });
});

it('caches mistral model listing with a short ttl', function () {
    config([
        'llm.providers.mistral.api_key' => 'test-mistral-key',
        'llm.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
    ]);

    Http::fake([
        'https://api.mistral.ai/v1/models' => Http::response([
            'data' => [
                ['id' => 'mistral-small-latest'],
                ['id' => 'mistral-large-latest'],
            ],
        ]),
    ]);

    $provider = new MistralProvider();

    $first = $provider->listModels();
    $second = $provider->listModels();

    expect($first)->toBe(['mistral-small-latest', 'mistral-large-latest'])
        ->and($second)->toBe(['mistral-small-latest', 'mistral-large-latest']);

    Http::assertSentCount(1);
});

it('maps mistral error responses to llm exception in provider', function () {
    config([
        'llm.providers.mistral.api_key' => 'bad-key',
        'llm.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
        'llm.providers.mistral.default_model' => 'mistral-large-latest',
    ]);

    Http::fake([
        'https://api.mistral.ai/v1/chat/completions' => Http::response([
            'error' => ['message' => 'invalid key'],
        ], 401),
    ]);

    $provider = new MistralProvider();

    $provider->generateText(new LlmRequest(
        messages: [new LlmMessage('user', 'hello')],
        model: 'mistral-large-latest',
    ));
})->throws(LlmException::class);
