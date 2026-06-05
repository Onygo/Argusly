<?php

use App\Enums\ContentLifecycleStatus;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Content\ContentLocalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('supports dry-run without persisting family, locale, or shadow changes', function () {
    [, , $source, $translation, $publication] = makeStaleContentStateScenario();

    $this->artisan('content:repair-state --dry-run')
        ->expectsOutputToContain('Dry run only. No changes were persisted.')
        ->assertExitCode(0);

    expect((string) $source->fresh()->family_id)->toBe('')
        ->and((string) $translation->fresh()->family_id)->toBe('')
        ->and((string) $translation->fresh()->status)->toBe('draft')
        ->and((string) $translation->fresh()->publish_status)->toBe('failed')
        ->and((string) $translation->fresh()->delivery_status)->toBe('pending')
        ->and((string) $publication->fresh()->getRawOriginal('locale'))->toBe('en');
});

it('repairs missing family ids, publication locales, and legacy shadow fields from canonical publications', function () {
    [, , $source, $translation, $publication] = makeStaleContentStateScenario();

    $this->artisan('content:repair-state')
        ->expectsOutputToContain('Content state repair completed.')
        ->assertExitCode(0);

    $source->refresh();
    $translation->refresh();
    $publication->refresh();

    expect((string) $source->family_id)->toBe((string) $source->id)
        ->and((string) $translation->family_id)->toBe((string) $source->id)
        ->and((string) $publication->getRawOriginal('locale'))->toBe('nl')
        ->and((string) $translation->delivery_status)->toBe('delivered')
        ->and((string) $translation->publish_status)->toBe('published')
        ->and((string) $translation->status)->toBe('published')
        ->and($translation->lifecycleStageEnum())->toBe(ContentLifecycleStatus::PUBLISHED)
        ->and((string) $translation->wp_post_id)->toBe('wp-nl-123')
        ->and((string) $translation->published_url)->toBe('https://repair-state.example.com/nl/article');
});

it('derives localization status matrix publication truth from content_publications instead of legacy flags', function () {
    [$workspace, $site] = createRepairStateContext();

    $source = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'English source',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    $translation = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Nederlandse variant',
        'language' => 'nl',
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'failed',
        'delivery_status' => 'pending',
        'source' => 'translation',
    ]);

    ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $translation->id,
        'client_site_id' => $site->id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'locale' => 'nl',
        'remote_id' => 'wp-nl-live',
        'remote_url' => 'https://repair-state.example.com/nl/live',
        'remote_status' => 'publish',
        'delivery_status' => 'delivered',
        'last_delivered_at' => now(),
    ]);

    $matrix = collect(app(ContentLocalizationService::class)->statusMatrix($source));
    $row = $matrix->firstWhere('locale', 'nl');

    expect($row)->not->toBeNull()
        ->and((bool) ($row['is_published'] ?? false))->toBeTrue()
        ->and((string) ($row['publish_status'] ?? ''))->toBe('published')
        ->and((string) ($row['delivery_status'] ?? ''))->toBe('delivered');
});

it('is idempotent when run twice', function () {
    [, , $source, $translation, $publication] = makeStaleContentStateScenario();

    $this->artisan('content:repair-state')->assertExitCode(0);
    $this->artisan('content:repair-state')
        ->expectsOutputToContain('Family repair: scanned=0 repaired=0')
        ->expectsOutputToContain('Publication locales: scanned=1 mismatches=0 repaired=0')
        ->expectsOutputToContain('Legacy shadows: scanned=2 repaired=0')
        ->assertExitCode(0);

    expect((string) $source->fresh()->family_id)->toBe((string) $source->id)
        ->and((string) $translation->fresh()->family_id)->toBe((string) $source->id)
        ->and((string) $publication->fresh()->getRawOriginal('locale'))->toBe('nl');
});

it('can scope repairs to a single site', function () {
    [$workspace, $siteA] = createRepairStateContext();
    [$workspaceB, $siteB] = createRepairStateContext();

    [, , $sourceA, $translationA, $publicationA] = makeStaleContentStateScenario($workspace, $siteA);
    [, , $sourceB, $translationB, $publicationB] = makeStaleContentStateScenario($workspaceB, $siteB);

    $this->artisan('content:repair-state --site=' . $siteA->id)->assertExitCode(0);

    expect((string) $sourceA->fresh()->family_id)->toBe((string) $sourceA->id)
        ->and((string) $translationA->fresh()->family_id)->toBe((string) $sourceA->id)
        ->and((string) $publicationA->fresh()->getRawOriginal('locale'))->toBe('nl')
        ->and((string) $translationB->fresh()->family_id)->toBe('')
        ->and((string) $publicationB->fresh()->getRawOriginal('locale'))->toBe('en')
        ->and((string) $sourceB->fresh()->family_id)->toBe('');
});

it('repairs publication providers and clears stale wordpress legacy errors for laravel content', function () {
    [$workspace, $site] = createRepairStateContext(siteType: ClientSite::TYPE_LARAVEL);

    $destination = \App\Models\ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Laravel Repair Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://repair-state.example.com',
                'site_id' => 'repair-site',
                'enabled' => true,
            ],
        ],
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'title' => 'Laravel repair article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'failed',
        'publish_error' => 'Webhook failed, http 405, WordPress connector create endpoint was not found.',
        'delivery_status' => 'failed',
        'source' => 'manual',
    ]);

    $publication = ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'destination_id' => $destination->id,
        'client_site_id' => $site->id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'locale' => 'en',
        'delivery_status' => 'delivered',
        'remote_id' => 'article-123',
        'remote_url' => 'https://repair-state.example.com/en/article',
        'remote_status' => 'published',
        'last_delivered_at' => now(),
    ]);

    $this->artisan('content:repair-state --content=' . $content->id)
        ->expectsOutputToContain('Publication states: scanned=1 repaired=1')
        ->assertExitCode(0);

    expect((string) $publication->fresh()->provider)->toBe(ContentPublication::PROVIDER_LARAVEL)
        ->and($content->fresh()->publish_error)->toBeNull()
        ->and((string) $content->fresh()->publish_status)->toBe('published')
        ->and((string) $content->fresh()->delivery_status)->toBe('delivered');
});

it('shows repaired language variants on the content detail page', function () {
    [$workspace, $site, $user] = createRepairStateContext(withUser: true);
    [, , $source, $translation] = makeStaleContentStateScenario($workspace, $site, $user);

    $this->artisan('content:repair-state')->assertExitCode(0);

    $this->actingAs($user)
        ->get(route('app.content.show', $source))
        ->assertOk()
        ->assertSee('Localization Operations')
        ->assertSee('EN (current)')
        ->assertSee('href="' . route('app.content.show', $translation) . '"', false)
        ->assertSee('Published');
});

/**
 * @return array{0:Workspace,1:ClientSite,2:?User}
 */
function createRepairStateContext(bool $withUser = false, string $siteType = ClientSite::TYPE_WORDPRESS): array
{
    $user = null;
    $organizationAttributes = [
        'name' => 'Repair State Org',
        'slug' => 'repair-state-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Repair State BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ];

    if ($withUser) {
        $user = User::query()->create([
            'name' => 'Repair State User',
            'email' => 'repair-state-' . Str::lower(Str::random(8)) . '@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
            'active' => true,
            'approved_at' => now(),
            'email_code_verified_at' => now(),
        ]);

        $organizationAttributes['primary_user_id'] = $user->id;
    }

    $organization = Organization::query()->create($organizationAttributes);

    if ($user instanceof User) {
        $user->forceFill([
            'organization_id' => $organization->id,
        ])->save();
    }

    $workspace = Workspace::query()->create([
        'name' => 'Repair State Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => $siteType,
        'name' => 'Repair State Site',
        'site_url' => 'https://repair-state.example.com',
        'base_url' => 'https://repair-state.example.com',
        'allowed_domains' => ['repair-state.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    if ($withUser && $user instanceof User) {
        $plan = Plan::query()->firstOrCreate(
            ['key' => 'repair-state-plan'],
            [
                'name' => 'Repair State Plan',
                'is_active' => true,
                'price_cents' => 0,
                'currency' => 'EUR',
                'interval' => 'month',
                'included_credits_per_interval' => 100,
            ]
        );

        Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'interval' => 'month',
            'price_cents' => 0,
            'currency' => 'EUR',
            'included_credits_per_interval' => 100,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);
    }

    return [$workspace, $site, $user];
}

/**
 * @return array{0:Workspace,1:ClientSite,2:Content,3:Content,4:ContentPublication}
 */
function makeStaleContentStateScenario(?Workspace $workspace = null, ?ClientSite $site = null, ?User $user = null): array
{
    if (! $workspace || ! $site) {
        [$workspace, $site] = createRepairStateContext();
    }

    $source = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Source article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'created_by' => $user?->id,
        'updated_by' => $user?->id,
    ]);

    $translation = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Nederlandse variant',
        'language' => 'nl',
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'failed',
        'delivery_status' => 'pending',
        'source' => 'translation',
        'created_by' => $user?->id,
        'updated_by' => $user?->id,
    ]);

    DB::table('contents')
        ->where('id', $source->id)
        ->update(['family_id' => null]);

    DB::table('contents')
        ->where('id', $translation->id)
        ->update([
            'family_id' => null,
            'status' => 'draft',
            'publish_status' => 'failed',
            'delivery_status' => 'pending',
            'published_url' => null,
            'wp_post_id' => null,
        ]);

    $publication = ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $translation->id,
        'client_site_id' => $site->id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'locale' => 'en',
        'remote_id' => 'wp-nl-123',
        'remote_url' => 'https://repair-state.example.com/nl/article',
        'remote_status' => 'publish',
        'delivery_status' => 'delivered',
        'last_delivered_at' => now(),
    ]);

    return [$workspace, $site, $source, $translation, $publication];
}
