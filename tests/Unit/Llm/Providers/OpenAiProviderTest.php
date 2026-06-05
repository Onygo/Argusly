<?php

use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Http;

it('passes json schema to openai responses api when schema is provided', function () {
    config([
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
    ]);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_schema_1',
            'model' => 'gpt-5.1',
            'output' => [
                [
                    'content' => [
                        ['text' => '{"ok":true}'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 4,
                'total_tokens' => 16,
            ],
        ]),
    ]);

    $provider = new OpenAiProvider();
    $provider->generateJson(new LlmRequest(
        messages: [new LlmMessage('user', 'Return JSON')],
        model: 'gpt-5.1',
    ), [
        'name' => 'draft_intelligence_analysis',
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

        return ($data['text']['format']['type'] ?? '') === 'json_schema'
            && ($data['text']['format']['name'] ?? '') === 'draft_intelligence_analysis'
            && is_array($data['text']['format']['schema'] ?? null)
            && ($data['text']['format']['strict'] ?? null) === true;
    });
});

it('strips markdown fences before decoding json responses', function () {
    config([
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
    ]);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_json_fence',
            'model' => 'gpt-5.1',
            'output' => [
                [
                    'content' => [
                        ['text' => "```json\n{\"ok\":true}\n```"],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 12,
                'output_tokens' => 8,
                'total_tokens' => 20,
            ],
        ]),
    ]);

    $provider = new OpenAiProvider();
    $response = $provider->generateJson(new LlmRequest(
        messages: [new LlmMessage('user', 'Return JSON')],
        model: 'gpt-5.1',
    ));

    expect($response->json)->toBe(['ok' => true]);
});

it('repairs raw control characters inside json string responses', function () {
    config([
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
    ]);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_json_control_chars',
            'model' => 'gpt-5.1',
            'output' => [
                [
                    'content' => [
                        ['text' => "{\"content_html\":\"<p>Line 1\nLine 2</p>\",\"change_summary\":\"Added CTA\"}"],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 14,
                'output_tokens' => 10,
                'total_tokens' => 24,
            ],
        ]),
    ]);

    $provider = new OpenAiProvider();
    $response = $provider->generateJson(new LlmRequest(
        messages: [new LlmMessage('user', 'Return JSON')],
        model: 'gpt-5.1',
    ));

    expect($response->json)->toBe([
        'content_html' => "<p>Line 1\nLine 2</p>",
        'change_summary' => 'Added CTA',
    ]);
});
