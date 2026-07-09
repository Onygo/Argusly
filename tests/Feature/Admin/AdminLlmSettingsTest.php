<?php

use App\Models\LlmGlobalSetting;
use App\Models\LlmRequest;
use App\Models\LlmRoutingRule;
use App\Models\LlmSettingsAuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAdminLlmUser(): array
{
    $organization = Organization::query()->create([
        'name' => 'Admin Org',
        'slug' => 'admin-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Admin Workspace',
        'organization_id' => $organization->id,
    ]);

    $admin = User::query()->create([
        'name' => 'Platform Admin',
        'email' => 'platform-admin+' . Str::lower(Str::random(5)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'is_admin' => true,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$admin, $workspace];
}

it('updates global llm settings and writes audit log', function () {
    [$admin] = makeAdminLlmUser();

    $this->actingAs($admin)
        ->post(route('admin.llm.settings.global.update'), [
            'default_text_provider' => 'anthropic',
            'default_image_provider' => 'openai',
            'default_text_model_map' => [
                'openai' => 'gpt-4.1-mini',
                'anthropic' => 'claude-3-5-sonnet-latest',
                'gemini' => 'gemini-2.0-flash',
            ],
            'default_image_model_map' => [
                'openai' => 'gpt-image-1',
                'anthropic' => '',
                'gemini' => '',
            ],
            'timeout_seconds' => 120,
            'retry_max' => 3,
            'retry_backoff_ms' => 900,
        ])
        ->assertRedirect();

    $settings = LlmGlobalSetting::query()->find(1);
    expect($settings)->not->toBeNull()
        ->and($settings->default_text_provider)->toBe('anthropic')
        ->and((int) $settings->retry_max)->toBe(3);

    expect(LlmSettingsAuditLog::query()->count())->toBe(1);
});

it('creates workspace routing rule and audit record', function () {
    [$admin, $workspace] = makeAdminLlmUser();

    $this->actingAs($admin)
        ->post(route('admin.llm.settings.rules.upsert'), [
            'scope_type' => 'workspace',
            'scope_id' => (string) $workspace->id,
            'feature' => 'draft_generation',
            'modality' => 'text',
            'inherit_global' => 0,
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'fallback_enabled' => 1,
            'fallback_provider' => 'openai',
            'fallback_model' => 'gpt-4.1-mini',
            'is_enabled' => 1,
        ])
        ->assertRedirect();

    $rule = LlmRoutingRule::query()->where('scope_type', 'workspace')->first();
    expect($rule)->not->toBeNull()
        ->and($rule->provider)->toBe('gemini')
        ->and($rule->fallback_enabled)->toBeTrue();

    expect(LlmSettingsAuditLog::query()->count())->toBe(1);
});

it('renders available provider models as selectable suggestions', function () {
    [$admin] = makeAdminLlmUser();

    Cache::flush();
    config([
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.base_url' => 'https://api.openai.test',
    ]);

    Http::fake([
        'https://api.openai.test/v1/models' => Http::response([
            'data' => [
                ['id' => 'gpt-live-option'],
                ['id' => 'gpt-image-live-option'],
            ],
        ]),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.llm.settings'))
        ->assertOk()
        ->assertSee('list="llm-models-openai-text"', false)
        ->assertSee('gpt-live-option')
        ->assertSee('gpt-image-live-option');
});

it('shows openai billing and auto recharge status from request logs', function () {
    [$admin] = makeAdminLlmUser();

    config([
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.project' => 'proj_argusly_prod',
        'llm.providers.openai.auto_recharge_enabled' => true,
        'argusly.ai.images.provider' => 'openai',
        'argusly.ai.images.openai.model' => 'gpt-image-1',
    ]);

    LlmRequest::query()->create([
        'workspace_id' => null,
        'site_id' => null,
        'feature' => 'image_generation',
        'modality' => 'image',
        'provider' => 'openai',
        'model' => 'gpt-image-1',
        'status' => 'error',
        'error_type' => 'RuntimeException',
        'error_code' => '400',
        'error_message' => 'Image generation failed: HTTP 400 - Billing hard limit has been reached.',
        'metadata' => ['trigger' => 'image_generation_service'],
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.llm.settings'))
        ->assertOk()
        ->assertSee('OpenAI billing status')
        ->assertSee('Auto recharge enabled, waiting for recovery')
        ->assertSee('Enabled in config')
        ->assertSee('proj_argusly_prod')
        ->assertSee('Billing hard limit has been reached')
        ->assertSee('No successful OpenAI request has been logged after the latest billing issue.');
});
