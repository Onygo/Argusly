<?php

use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Facades\Http;

it('handles anthropic success with json parsing and usage mapping', function () {
    config([
        'llm.default_provider' => 'anthropic',
        'llm.providers.anthropic.api_key' => 'test-anthropic-key',
        'llm.providers.anthropic.base_url' => 'https://api.anthropic.com',
        'llm.providers.anthropic.default_model' => 'claude-3-5-sonnet-latest',
    ]);

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'id' => 'msg_123',
            'model' => 'claude-3-5-sonnet-latest',
            'content' => [
                ['type' => 'text', 'text' => '{"ok":true,"title":"Claude"}'],
            ],
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 34,
            ],
        ]),
    ]);

    $manager = app(LlmManager::class);
    $response = $manager->generateJson(new LlmRequest(
        messages: [
            new LlmMessage('system', 'System context'),
            new LlmMessage('user', 'Return JSON'),
        ],
        model: 'claude-3-5-sonnet-latest',
        responseFormat: 'json',
        metadata: ['provider' => 'anthropic'],
    ));

    expect($response->providerName)->toBe('anthropic')
        ->and($response->json)->toBe(['ok' => true, 'title' => 'Claude'])
        ->and($response->usage->inputTokens)->toBe(12)
        ->and($response->usage->outputTokens)->toBe(34)
        ->and($response->usage->totalTokens)->toBe(46);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && ($data['system'] ?? null) === 'System context'
            && ($data['messages'][0]['role'] ?? null) === 'user';
    });
});

it('maps anthropic auth errors to llm exception', function () {
    config([
        'llm.default_provider' => 'anthropic',
        'llm.fallback.default_enabled' => false,
        'llm.providers.anthropic.api_key' => 'bad-key',
        'llm.providers.anthropic.base_url' => 'https://api.anthropic.com',
    ]);

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'error' => ['message' => 'invalid x-api-key'],
        ], 401),
    ]);

    $manager = app(LlmManager::class);

    $manager->generateText(new LlmRequest(
        messages: [new LlmMessage('user', 'Hello')],
        model: 'claude-3-5-sonnet-latest',
        metadata: ['provider' => 'anthropic'],
    ));
})->throws(LlmException::class);

it('handles gemini success with json mode and request mapping', function () {
    config([
        'llm.default_provider' => 'gemini',
        'llm.providers.gemini.api_key' => 'test-gemini-key',
        'llm.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'llm.providers.gemini.default_model' => 'gemini-2.0-flash',
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=test-gemini-key' => Http::response([
            'responseId' => 'gem_resp_1',
            'modelVersion' => 'gemini-2.0-flash',
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            ['text' => '{"ok":true,"title":"Gemini"}'],
                        ],
                    ],
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 20,
                'candidatesTokenCount' => 15,
                'totalTokenCount' => 35,
            ],
        ]),
    ]);

    $manager = app(LlmManager::class);
    $response = $manager->generateJson(new LlmRequest(
        messages: [
            new LlmMessage('system', 'You are strict.'),
            new LlmMessage('user', 'Return JSON only.'),
        ],
        model: 'gemini-2.0-flash',
        responseFormat: 'json',
        metadata: ['provider' => 'gemini'],
    ));

    expect($response->providerName)->toBe('gemini')
        ->and($response->requestId)->toBe('gem_resp_1')
        ->and($response->json)->toBe(['ok' => true, 'title' => 'Gemini'])
        ->and($response->usage->totalTokens)->toBe(35);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), ':generateContent?key=test-gemini-key')
            && ($data['systemInstruction']['parts'][0]['text'] ?? '') === 'You are strict.'
            && ($data['generationConfig']['responseMimeType'] ?? '') === 'application/json';
    });
});

it('maps gemini rate limit errors to llm exception', function () {
    config([
        'llm.default_provider' => 'gemini',
        'llm.fallback.default_enabled' => false,
        'llm.providers.gemini.api_key' => 'test-gemini-key',
        'llm.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ]);

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=test-gemini-key' => Http::response([
            'error' => ['message' => 'rate limit'],
        ], 429),
    ]);

    $manager = app(LlmManager::class);

    $manager->generateText(new LlmRequest(
        messages: [new LlmMessage('user', 'Hello')],
        model: 'gemini-2.0-flash',
        metadata: ['provider' => 'gemini'],
    ));
})->throws(LlmException::class);

it('handles mistral success with json mode and request mapping', function () {
    config([
        'llm.default_provider' => 'mistral',
        'llm.providers.mistral.api_key' => 'test-mistral-key',
        'llm.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
        'llm.providers.mistral.default_model' => 'mistral-large-latest',
    ]);

    Http::fake([
        'https://api.mistral.ai/v1/chat/completions' => Http::response([
            'id' => 'cmpl_mistral_1',
            'model' => 'mistral-large-latest',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"ok":true,"title":"Mistral"}',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 18,
                'completion_tokens' => 12,
                'total_tokens' => 30,
            ],
        ]),
    ]);

    $manager = app(LlmManager::class);
    $response = $manager->generateJson(new LlmRequest(
        messages: [
            new LlmMessage('system', 'You are strict.'),
            new LlmMessage('user', 'Return JSON only.'),
        ],
        model: 'mistral-large-latest',
        temperature: 0.2,
        maxTokens: 120,
        responseFormat: 'json',
        metadata: [
            'provider' => 'mistral',
            'stop' => ['END'],
            'safe_prompt' => true,
            'random_seed' => 7,
        ],
    ));

    expect($response->providerName)->toBe('mistral')
        ->and($response->requestId)->toBe('cmpl_mistral_1')
        ->and($response->json)->toBe(['ok' => true, 'title' => 'Mistral'])
        ->and($response->usage->inputTokens)->toBe(18)
        ->and($response->usage->outputTokens)->toBe(12)
        ->and($response->usage->totalTokens)->toBe(30);

    Http::assertSent(function ($request) {
        $data = $request->data();
        $roles = collect((array) ($data['messages'] ?? []))->pluck('role')->all();

        return $request->url() === 'https://api.mistral.ai/v1/chat/completions'
            && in_array('system', $roles, true)
            && in_array('user', $roles, true)
            && ($data['response_format']['type'] ?? '') === 'json_object'
            && ($data['max_tokens'] ?? null) === 120
            && ($data['temperature'] ?? null) === 0.2
            && ($data['stop'] ?? []) === ['END']
            && ($data['safe_prompt'] ?? null) === true
            && ($data['random_seed'] ?? null) === 7;
    });
});

it('maps mistral auth errors to llm exception', function () {
    config([
        'llm.default_provider' => 'mistral',
        'llm.fallback.default_enabled' => false,
        'llm.providers.mistral.api_key' => 'bad-key',
        'llm.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
        'llm.providers.mistral.default_model' => 'mistral-large-latest',
    ]);

    Http::fake([
        'https://api.mistral.ai/v1/chat/completions' => Http::response([
            'error' => ['message' => 'invalid api key'],
        ], 401),
    ]);

    $manager = app(LlmManager::class);

    $manager->generateText(new LlmRequest(
        messages: [new LlmMessage('user', 'Hello')],
        model: 'mistral-large-latest',
        metadata: ['provider' => 'mistral'],
    ));
})->throws(LlmException::class);
