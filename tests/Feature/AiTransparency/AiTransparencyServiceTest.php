<?php

use App\Models\Content;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiTransparency\AiTransparencyService;
use App\Services\Api\ApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates a machine readable disclosure with model provenance for content', function (): void {
    [$content, $user, $brief] = createAiTransparencyTestContent();
    Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $content->client_site_id,
        'status' => 'draft',
        'title' => 'AI Transparency Draft',
        'language' => 'en',
        'model_used' => 'gpt-5.1',
        'content_html' => '<p>Generated content with traceability.</p>',
        'meta' => [
            'provider' => 'openai',
            'request_id' => 'req-transparency-test',
            'prompt' => 'Write a transparent article.',
        ],
    ]);

    $service = app(AiTransparencyService::class);
    $record = $service->ensureForContent($content->fresh(['workspace', 'drafts']));
    $service->recordHumanReview($record, ['status' => 'approved'], $user);
    $record = $record->fresh(['modelRuns', 'promptVersions', 'humanReviews']);
    $payload = $service->provenancePayload($record);

    expect($payload['record']['ai_origin'])->toBe('ai_generated')
        ->and($payload['record']['machine_metadata']['@type'])->toBe('argusly:AiContentDisclosure')
        ->and($payload['model_history'])->toHaveCount(1)
        ->and($payload['model_history'][0]['model'])->toBe('gpt-5.1')
        ->and($payload['prompt_history'])->toHaveCount(1)
        ->and($payload['human_reviews'][0]['status'])->toBe('approved')
        ->and($record->trust_score)->toBeGreaterThan(50);
});

it('captures production generation metadata and source traces', function (): void {
    [$content, $user, $brief] = createAiTransparencyTestContent();
    Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $content->client_site_id,
        'status' => 'generated',
        'title' => 'Production Metadata Draft',
        'language' => 'en',
        'model_used' => 'gpt-5.1',
        'content_html' => '<p>Generated content with production metadata.</p>',
        'meta' => [
            'generation' => [
                'provider' => 'openai',
                'model_used' => 'gpt-5.1',
                'request_id' => 'req-production-metadata',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 200,
                    'total_tokens' => 300,
                ],
                'settings' => [
                    'temperature' => 0.3,
                    'response_format' => 'json',
                ],
                'prompt_snapshot' => [
                    'system' => [
                        'hash' => 'sha256:' . hash('sha256', 'system prompt'),
                        'summary' => 'system prompt',
                        'contains_redactions' => true,
                    ],
                    'user' => [
                        'hash' => 'sha256:' . hash('sha256', 'user prompt'),
                        'summary' => 'user prompt',
                        'contains_redactions' => true,
                    ],
                ],
            ],
        ],
    ]);

    $service = app(AiTransparencyService::class);
    $record = $service->ensureForContent($content->fresh(['workspace', 'drafts']));
    $service->recordSourceTrace($record, [
        'source_type' => 'url',
        'url' => 'https://example.com/source',
        'title' => 'Example source',
        'reliability_score' => 90,
        'used_for_sections' => ['Overview'],
    ]);
    $payload = $service->provenancePayload($record->fresh(['modelRuns', 'promptVersions', 'sourceTraces']));

    expect($payload['model_history'])->toHaveCount(1)
        ->and($payload['model_history'][0]['provider'])->toBe('openai')
        ->and($payload['model_history'][0]['run_id'])->toBe('req-production-metadata')
        ->and($payload['model_history'][0]['usage']['total_tokens'])->toBe(300)
        ->and($payload['prompt_history'])->toHaveCount(2)
        ->and($payload['source_trace'])->toHaveCount(1)
        ->and($payload['source_trace'][0]['title'])->toBe('Example source');
});

it('downloads an AI audit report PDF from the app trust center', function (): void {
    [$content, $user] = createAiTransparencyTestContent();

    $response = test()
        ->actingAs($user)
        ->get(route('app.content.ai-trust.audit-report', $content));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
});

it('serves disclosure API responses for site tokens', function (): void {
    [$content] = createAiTransparencyTestContent();
    $plainToken = 'site-token-ai-transparency-test';

    SiteToken::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $content->client_site_id,
        'workspace_id' => $content->workspace_id,
        'name' => 'AI transparency test token',
        'token_hash' => hash('sha256', $plainToken),
        'key_prefix' => 'site-token-ai',
        'scopes' => [ApiScopes::CONTENT_READ],
        'abilities' => [ApiScopes::CONTENT_READ],
        'revoked' => false,
    ]);

    test()
        ->withHeaders([
            'Authorization' => 'Bearer ' . $plainToken,
            'X-Argusly-Site' => 'https://trust.example.com',
        ])
        ->getJson('/api/v1/content/' . $content->id . '/ai-disclosure')
        ->assertOk()
        ->assertJsonPath('data.content_id', $content->id)
        ->assertJsonPath('data.machine_metadata.@type', 'argusly:AiContentDisclosure');
});

function createAiTransparencyTestContent(): array
{
    $suffix = Str::lower(Str::random(6));

    $organization = Organization::create([
        'name' => 'AI Trust Org ' . $suffix,
        'slug' => 'ai-trust-org-' . $suffix,
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'AI Trust BV',
        'billing_address_line1' => 'Trust Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'AI Trust Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'ai-trust-test-plan'],
        [
            'name' => 'AI Trust Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::create([
        'name' => 'AI Trust User',
        'email' => 'ai-trust-' . $suffix . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'email_verified_at' => now(),
        'approved_at' => now(),
        'is_admin' => false,
    ]);

    $site = ClientSite::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'AI Trust Site',
        'site_url' => 'https://trust.example.com',
        'base_url' => 'https://trust.example.com',
        'allowed_domains' => ['trust.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AI Transparency Content',
        'language' => 'en',
        'translation_source_locale' => 'en',
        'is_source_locale' => true,
        'primary_keyword' => 'ai transparency',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'origin_type' => 'manual',
        'delivery_status' => 'pending',
    ]);

    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'ready',
        'title' => 'AI Transparency Brief',
        'language' => 'en',
        'output_type' => 'article',
    ]);

    return [$content, $user, $brief];
}
