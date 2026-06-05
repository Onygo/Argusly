<?php

use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('logs llm request success entries from manager calls', function () {
    config([
        'llm.default_provider' => 'openai',
        'llm.providers.openai.api_key' => 'test-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
        'llm.providers.openai.default_model' => 'gpt-4.1-mini',
        'llm.pricing.usd_to_eur_rate' => 1.0,
    ]);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_1',
            'model' => 'gpt-4.1-mini',
            'output_text' => 'ok',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13],
        ]),
    ]);

    $manager = app(LlmManager::class);
    $manager->generateText(new LlmRequest(
        messages: [new LlmMessage('user', 'Hello world')],
        metadata: [
            'feature' => 'intelligence_analysis',
            'workspaceId' => null,
            'siteId' => null,
            'credits' => 2,
        ],
    ));

    $this->assertDatabaseHas('llm_requests', [
        'feature' => 'intelligence_analysis',
        'provider' => 'openai',
        'status' => 'success',
        'input_tokens' => 10,
        'output_tokens' => 3,
        'total_tokens' => 13,
        'input_cost_eur' => 0.000004,
        'output_cost_eur' => 0.0000048,
        'total_cost_eur' => 0.0000088,
    ]);
});

it('uses the longest matching pricing rule for dated model ids', function () {
    config([
        'llm.pricing.usd_to_eur_rate' => 1.0,
    ]);

    $cost = app(\App\Services\Llm\LlmCostEstimator::class)->estimate(
        provider: 'openai',
        model: 'gpt-5.1-2025-11-13',
        inputTokens: 1_000_000,
        outputTokens: 1_000_000,
    );

    expect($cost['input_cost'])->toBe(1.25)
        ->and($cost['output_cost'])->toBe(10.0)
        ->and($cost['total_cost'])->toBe(11.25);
});

it('logs llm request error entries from manager calls', function () {
    config([
        'llm.default_provider' => 'openai',
        'llm.providers.openai.api_key' => 'test-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
        'llm.providers.openai.default_model' => 'gpt-4.1-mini',
    ]);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'error' => ['message' => 'Unauthorized'],
        ], 401),
    ]);

    $manager = app(LlmManager::class);

    try {
        $manager->generateText(new LlmRequest(
            messages: [new LlmMessage('user', 'Hello world')],
            metadata: [
                'feature' => 'draft_generation',
            ],
        ));
    } catch (LlmException) {
        // expected
    }

    $this->assertDatabaseHas('llm_requests', [
        'feature' => 'draft_generation',
        'provider' => 'openai',
        'status' => 'error',
        'error_code' => '401',
    ]);
});

it('falls back to openai when default provider fails with quota error', function () {
    config([
        'llm.default_provider' => 'anthropic',
        'llm.fallback.default_enabled' => true,
        'llm.fallback.default_provider' => 'openai',
        'llm.providers.anthropic.api_key' => 'anthropic-test-key',
        'llm.providers.anthropic.base_url' => 'https://api.anthropic.com',
        'llm.providers.anthropic.default_model' => 'claude-3-5-sonnet-latest',
        'llm.providers.openai.api_key' => 'openai-test-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
        'llm.providers.openai.default_model' => 'gpt-4.1-mini',
    ]);

    Http::fake([
        'https://api.anthropic.com/v1/messages' => Http::response([
            'error' => ['message' => 'Your credit balance is too low to access the Anthropic API.'],
        ], 400),
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_fallback_1',
            'model' => 'gpt-4.1-mini',
            'output_text' => 'fallback-ok',
            'usage' => ['input_tokens' => 9, 'output_tokens' => 2, 'total_tokens' => 11],
        ], 200),
    ]);

    $manager = app(LlmManager::class);
    $response = $manager->generateText(new LlmRequest(
        messages: [new LlmMessage('user', 'Hello fallback')],
        metadata: [
            'feature' => 'draft_generation',
        ],
    ));

    expect($response->providerName)->toBe('openai')
        ->and($response->text)->toContain('fallback-ok');

    $this->assertDatabaseHas('llm_requests', [
        'feature' => 'draft_generation',
        'provider' => 'anthropic',
        'status' => 'error',
        'error_code' => '400',
    ]);

    $this->assertDatabaseHas('llm_requests', [
        'feature' => 'draft_generation',
        'provider' => 'openai',
        'status' => 'success',
    ]);
});
