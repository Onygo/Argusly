<?php

use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\ClientSite;
use App\Services\CreditWalletService;
use App\Services\Content\ContentLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('inbound brief creates content and brief version', function () {
    $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . Str::random(6), 'status' => 'active']);
    $workspace = Workspace::create(['name' => 'Ws', 'organization_id' => $org->id]);
    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    $plain = 'arg_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['briefs:write'],
        'revoked' => false,
    ]);
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'example.com',
    ])->postJson('/api/v1/briefs', [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://example.com',
            'wp_post_id' => '1234',
        ],
        'brief' => [
            'title' => 'Lifecycle content',
            'language' => 'en',
            'intent_keys' => ['technical'],
            'audience_keys' => ['developer'],
            'output_type' => 'kb_article',
        ],
    ])->assertStatus(201);

    $content = Content::query()->first();
    expect($content)->not->toBeNull();
    expect(in_array($content->status, ['brief', 'draft'], true))->toBeTrue();
    expect(ContentVersion::query()->where('content_id', $content->id)->where('type', 'brief')->exists())->toBeTrue();
});

it('draft generation creates draft then revision versions and restore works', function () {
    $org = Organization::create(['name' => 'Org2', 'slug' => 'org2-' . Str::random(6), 'status' => 'active']);
    $workspace = Workspace::create(['name' => 'Ws2', 'organization_id' => $org->id]);
    $content = Content::create([
        'workspace_id' => $workspace->id,
        'status' => 'brief',
        'title' => 'Title',
        'type' => 'article',
        'source' => 'wp',
    ]);

    $briefVersion = ContentVersion::create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'brief',
        'body' => '{"title":"brief"}',
        'source' => 'wp',
    ]);

    $draftV1 = ContentVersion::create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'parent_version_id' => $briefVersion->id,
        'body' => '<p>v1</p>',
        'source' => 'pl',
    ]);
    $content->update(['current_version_id' => $draftV1->id, 'status' => 'draft']);

    $draftV2 = ContentVersion::create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'revision',
        'parent_version_id' => $draftV1->id,
        'body' => '<p>v2</p>',
        'source' => 'pl',
    ]);
    $content->update(['current_version_id' => $draftV2->id]);

    $restored = app(ContentLifecycleService::class)->restoreVersion($content->fresh(), $draftV1, null);

    expect($restored->type)->toBe('revision');
    expect($restored->parent_version_id)->toBe((string) $draftV2->id);
    expect($content->fresh()->current_version_id)->toBe((string) $restored->id);
});

it('workspace user can access content index', function () {
    $org = Organization::create([
        'name' => 'Org3',
        'slug' => 'org3-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Org3 BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);
    $workspace = Workspace::create(['name' => 'Ws3', 'organization_id' => $org->id]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $org->id,
        'workspace_id' => $workspace->id,
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
        'name' => 'Owner',
        'email' => 'owner+' . Str::random(4) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $org->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    Content::create([
        'workspace_id' => $workspace->id,
        'status' => 'brief',
        'title' => 'Demo content',
        'type' => 'article',
        'source' => 'wp',
    ]);

    $this->actingAs($user)
        ->get(route('app.content.index'))
        ->assertOk()
        ->assertSee('Demo content');
});

it('content index query falls back to translation source roots when family_id is unavailable', function () {
    $contentWithoutFamilyColumn = new class extends Content
    {
        public static function supportsFamilyId(): bool
        {
            return false;
        }
    };

    $sql = $contentWithoutFamilyColumn->newQuery()
        ->whereInLocalizationRoots(['root-1'])
        ->toSql();

    expect($contentWithoutFamilyColumn::localizationRootExpression('contents'))->toBe('COALESCE(contents.translation_source_content_id, contents.id)')
        ->and($sql)->toContain('translation_source_content_id')
        ->and($sql)->not->toContain('family_id');
});
