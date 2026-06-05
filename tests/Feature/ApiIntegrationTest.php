<?php

use App\Models\Brief;
use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Persona;
use App\Models\SiteToken;
use App\Models\TeamMember;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedSiteCredits(ClientSite $site, int $amount = 100): void
{
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: $amount,
        type: CreditWalletService::TYPE_ALLOWANCE
    );
}

it('accepts brief creation via site token', function () {
    $organization = Organization::create([
        'name' => 'WP Org',
        'slug' => 'wp-org',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Example Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write', 'drafts:read', 'events:write'],
        'revoked' => false,
    ]);
    seedSiteCredits($site);

    $payload = [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'wp_brief_id' => 'wp-1',
        ],
        'brief' => [
            'title' => 'Test Brief',
            'language' => 'en',
            'intent_keys' => ['technical'],
            'audience_keys' => ['developer'],
            'output_type' => 'kb_article',
        ],
        'webhook' => [
            'draft_url' => 'https://example.com/webhook',
            'secret' => 'secret',
        ],
    ];

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'example.com',
    ])->postJson('/api/v1/briefs', $payload);

    $response->assertStatus(201)
        ->assertJsonStructure(['id', 'status', 'created_at']);
});

it('accepts nested brief intent keys via site token without returning 422', function () {
    $organization = Organization::create([
        'name' => 'WP Org Nested Intent',
        'slug' => 'wp-org-nested-intent',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace Nested Intent',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Nested Intent Site',
        'site_url' => 'https://nested-intent.example.com',
        'allowed_domains' => ['nested-intent.example.com'],
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write', 'drafts:read', 'events:write'],
        'revoked' => false,
    ]);
    seedSiteCredits($site);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'nested-intent.example.com',
    ])->postJson('/api/v1/briefs', [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://nested-intent.example.com',
            'wp_brief_id' => 'wp-nested-1',
        ],
        'brief' => [
            'title' => 'Nested intent brief',
            'language' => 'en',
            'intent' => [
                'keys' => ['educate', 'explain', 'guide'],
            ],
            'audience_keys' => ['developer'],
            'output_type' => 'kb_article',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('taxonomy.intent_keys', ['educate', 'explain', 'guide'])
        ->assertJsonMissingValidationErrors(['brief.intent.keys', 'brief.audience_keys']);
});

it('normalizes legacy flat brief intent input before validation', function () {
    $organization = Organization::create([
        'name' => 'WP Org Legacy Intent',
        'slug' => 'wp-org-legacy-intent',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace Legacy Intent',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Legacy Intent Site',
        'site_url' => 'https://legacy-intent.example.com',
        'allowed_domains' => ['legacy-intent.example.com'],
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write', 'drafts:read', 'events:write'],
        'revoked' => false,
    ]);
    seedSiteCredits($site);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'legacy-intent.example.com',
    ])->postJson('/api/v1/briefs', [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://legacy-intent.example.com',
            'wp_brief_id' => 'wp-legacy-1',
        ],
        'brief' => [
            'title' => 'Legacy intent brief',
            'language' => 'en',
            'intent' => 'educate',
            'audience_keys' => ['developer'],
            'output_type' => 'kb_article',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('taxonomy.intent_keys', ['educate']);
});

it('provides default intent keys when none are submitted', function () {
    $organization = Organization::create([
        'name' => 'WP Org Default Intent',
        'slug' => 'wp-org-default-intent',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace Default Intent',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Default Intent Site',
        'site_url' => 'https://default-intent.example.com',
        'allowed_domains' => ['default-intent.example.com'],
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write', 'drafts:read', 'events:write'],
        'revoked' => false,
    ]);
    seedSiteCredits($site);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'default-intent.example.com',
    ])->postJson('/api/v1/briefs', [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://default-intent.example.com',
            'wp_brief_id' => 'wp-default-1',
        ],
        'brief' => [
            'title' => 'Brief without intent keys',
            'language' => 'en',
            'output_type' => 'kb_article',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonMissingValidationErrors(['brief.intent.keys', 'brief.intent_keys', 'brief.audience_keys'])
        ->assertJsonPath('taxonomy.intent_keys', ['educate', 'explain', 'guide'])
        ->assertJsonPath('taxonomy.audience_keys', ['operations']);
});

it('provides landing page default intent keys based on output type', function () {
    $organization = Organization::create([
        'name' => 'WP Org Landing Intent',
        'slug' => 'wp-org-landing-intent',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace Landing Intent',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Landing Intent Site',
        'site_url' => 'https://landing-intent.example.com',
        'allowed_domains' => ['landing-intent.example.com'],
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write', 'drafts:read', 'events:write'],
        'revoked' => false,
    ]);
    seedSiteCredits($site);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'landing-intent.example.com',
    ])->postJson('/api/v1/briefs', [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://landing-intent.example.com',
            'wp_brief_id' => 'wp-landing-1',
        ],
        'brief' => [
            'title' => 'Landing page brief',
            'language' => 'en',
            'output_type' => 'seo_page',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonMissingValidationErrors(['brief.intent.keys', 'brief.intent_keys'])
        ->assertJsonPath('taxonomy.intent_keys', ['convert', 'persuade', 'explain']);
});

it('blocks brief draft generation via api when credits are insufficient', function () {
    $organization = Organization::create([
        'name' => 'WP Org No Credits',
        'slug' => 'wp-org-no-credits',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace No Credits',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Example Site No Credits',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write', 'drafts:read', 'events:write'],
        'revoked' => false,
    ]);

    $payload = [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'wp_brief_id' => 'wp-no-credits-1',
        ],
        'brief' => [
            'title' => 'No credits brief',
            'language' => 'en',
            'intent_keys' => ['technical'],
            'audience_keys' => ['developer'],
            'output_type' => 'kb_article',
        ],
        'webhook' => [
            'draft_url' => 'https://example.com/webhook',
            'secret' => 'secret',
        ],
    ];

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'example.com',
    ])->postJson('/api/v1/briefs', $payload);

    $response->assertStatus(422)
        ->assertJsonStructure(['error', 'required', 'available', 'action']);
    expect((string) $response->json('error'))->toContain('Insufficient credits. Required:');
});

it('blocks draft generate endpoint via api when credits are insufficient', function () {
    $organization = Organization::create([
        'name' => 'WP Org Draft No Credits',
        'slug' => 'wp-org-draft-no-credits',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace Draft No Credits',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft No Credits Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    $brief = Brief::create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Draft No Credits Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Draft No Credits',
        'output_type' => 'kb_article',
        'credit_cost' => 4,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['drafts:write'],
        'revoked' => false,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'example.com',
    ])->postJson('/api/v1/drafts/' . $draft->id . '/generate', []);

    $response->assertStatus(422)
        ->assertJsonStructure(['error', 'required', 'available', 'action']);
    expect((string) $response->json('error'))->toContain('Insufficient credits. Required:');
});

it('lists drafts via site token', function () {
    $organization = Organization::create([
        'name' => 'WP Org 2',
        'slug' => 'wp-org-2',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Example Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['drafts:read'],
        'revoked' => false,
    ]);

    $brief = Brief::create([
        'client_site_id' => $site->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    Draft::create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Draft',
        'output_type' => 'kb_article',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'example.com',
    ])->getJson('/api/v1/drafts?status=ready');

    $response->assertStatus(200)
        ->assertJsonStructure(['items']);
});

it('returns generation options for wp plugin dropdowns', function () {
    $organization = Organization::create([
        'name' => 'WP Org 3',
        'slug' => 'wp-org-3',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace 3',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Example Site 3',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    $defaultVoice = BrandVoice::create([
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'name' => 'Corporate Professional',
        'default_language' => 'en',
        'default_tone' => 'Professional',
        'is_default' => true,
    ]);

    TeamMember::create([
        'organization_id' => $organization->id,
        'name' => 'Alex Architect',
        'role' => 'CTO',
        'expertise' => 'AI and software architecture',
        'writing_perspective' => 'Technical',
        'personality_traits' => 'Pragmatic',
        'is_active' => true,
    ]);

    $buyerPersona = Persona::create([
        'organization_id' => $organization->id,
        'type' => Persona::TYPE_BUYER,
        'name' => 'Operations Manager Olivia',
        'source_type' => 'manual',
        'profile_data' => ['role' => 'Operations Manager'],
        'status' => Persona::STATUS_APPROVED,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write'],
        'revoked' => false,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'example.com',
    ])->getJson('/api/v1/generation/options');

    $response->assertStatus(200)
        ->assertJsonPath('defaults.preferred_length', 'medium')
        ->assertJsonPath('defaults.brand_voice_id', (string) $defaultVoice->id)
        ->assertJsonPath('defaults.buyer_persona_id', $buyerPersona->id)
        ->assertJsonStructure([
            'defaults' => ['brand_voice_id', 'buyer_persona_id', 'team_member_id', 'preferred_length'],
            'brand_voices',
            'buyer_personas',
            'team_members',
            'lengths',
        ]);
});

it('stores generation preferences from wp brief payload on content and draft', function () {
    $organization = Organization::create([
        'name' => 'WP Org 4',
        'slug' => 'wp-org-4',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace 4',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Example Site 4',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    $voice = BrandVoice::create([
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'name' => 'Founder Voice',
        'default_language' => 'en',
        'default_tone' => 'Bold',
        'is_default' => true,
    ]);

    $member = TeamMember::create([
        'organization_id' => $organization->id,
        'name' => 'Sara Founder',
        'role' => 'Founder',
        'expertise' => 'Go-to-market',
        'writing_perspective' => 'Commercial',
        'personality_traits' => 'Direct',
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write', 'drafts:read'],
        'revoked' => false,
    ]);
    seedSiteCredits($site);

    $payload = [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'wp_brief_id' => 'wp-42',
        ],
        'brief' => [
            'title' => 'Generation Prefs Test',
            'language' => 'en',
            'intent_keys' => ['technical'],
            'audience_keys' => ['developer'],
            'brand_voice_id' => (string) $voice->id,
            'team_member_id' => (int) $member->id,
            'preferred_length' => 'long',
            'output_type' => 'kb_article',
        ],
        'webhook' => [
            'draft_url' => 'https://example.com/webhook',
            'secret' => 'secret',
        ],
    ];

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'example.com',
    ])->postJson('/api/v1/briefs', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('generation.brand_voice_id', (string) $voice->id)
        ->assertJsonPath('generation.team_member_id', (int) $member->id)
        ->assertJsonPath('generation.preferred_length', 'long');

    $contentId = (string) $response->json('content_id');
    $draftId = (string) $response->json('draft_id');

    $this->assertDatabaseHas('contents', [
        'id' => $contentId,
        'brand_voice_id' => (string) $voice->id,
        'team_member_id' => (int) $member->id,
        'preferred_length' => 'long',
    ]);

    $draft = Draft::query()->findOrFail($draftId);
    expect(data_get($draft->meta, 'brand_voice_id'))->toBe((string) $voice->id);
    expect((int) data_get($draft->meta, 'team_member_id'))->toBe((int) $member->id);
    expect(data_get($draft->meta, 'preferred_length'))->toBe('long');
});

it('treats duplicate wp brief submissions as idempotent replay and avoids duplicate drafts', function () {
    $organization = Organization::create([
        'name' => 'WP Org 5',
        'slug' => 'wp-org-5',
        'status' => 'active',
    ]);
    $workspace = Workspace::create([
        'name' => 'WP Workspace 5',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Example Site 5',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write', 'drafts:read'],
        'revoked' => false,
    ]);
    seedSiteCredits($site);

    $payload = [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'wp_brief_id' => 'wp-replay-42',
            'wp_post_id' => 'post-42',
        ],
        'brief' => [
            'title' => 'Idempotent Brief',
            'language' => 'en',
            'intent_keys' => ['technical'],
            'audience_keys' => ['developer'],
            'output_type' => 'kb_article',
        ],
        'webhook' => [
            'draft_url' => 'https://example.com/webhook',
            'secret' => 'secret',
        ],
    ];

    $first = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'example.com',
    ])->postJson('/api/v1/briefs', $payload);

    $first->assertStatus(201);
    $briefId = (string) $first->json('id');
    $draftId = (string) $first->json('draft_id');

    $second = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'example.com',
    ])->postJson('/api/v1/briefs', $payload);

    $second->assertStatus(200)
        ->assertJsonPath('id', $briefId)
        ->assertJsonPath('draft_id', $draftId)
        ->assertJsonPath('idempotent_replay', true);

    expect(Brief::query()->where('client_site_id', $site->id)->count())->toBe(1);
    expect(Draft::query()->where('client_site_id', $site->id)->count())->toBe(1);
});

it('keeps a single wp publish target when draft ack is replayed', function () {
    $organization = Organization::create([
        'name' => 'WP Org Ack',
        'slug' => 'wp-org-ack',
        'status' => 'active',
    ]);

    $workspace = Workspace::create([
        'name' => 'WP Workspace Ack',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Ack Site',
        'site_url' => 'https://ack.example.com',
        'allowed_domains' => ['ack.example.com'],
        'is_active' => true,
    ]);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Ack content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ]);

    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Ack brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'title' => 'Ack draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>ack</p>',
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['drafts:write', 'drafts:ack'],
        'revoked' => false,
    ]);

    $headers = [
        'Authorization' => 'Bearer ' . $plain,
        'X-PublishLayer-Site' => 'ack.example.com',
    ];

    $payload = [
        'data' => [
            'wp_draft_id' => '44',
            'wp_post_id' => 'post-44',
        ],
    ];

    $this->withHeaders($headers)->postJson('/api/v1/drafts/' . $draft->id . '/ack', $payload)->assertOk();
    $this->withHeaders($headers)->postJson('/api/v1/drafts/' . $draft->id . '/ack', $payload)->assertOk();

    expect(ContentPublishTarget::query()
        ->where('content_id', $content->id)
        ->where('client_site_id', $site->id)
        ->where('target_type', 'wp')
        ->count())->toBe(1);
});
