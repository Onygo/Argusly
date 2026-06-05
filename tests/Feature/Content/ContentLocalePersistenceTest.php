<?php

use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BriefProcessing\BriefToDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createContentLocalePersistenceContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Locale Org',
        'slug' => 'content-locale-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Locale BV',
        'billing_address_line1' => 'Straat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Locale Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => SupportedLanguage::NL->value,
        'enabled_content_languages' => [SupportedLanguage::NL->value, SupportedLanguage::EN->value],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Locale Site',
        'site_url' => 'https://content-locale.example.com',
        'allowed_domains' => ['content-locale.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'content-locale-plan'],
        [
            'name' => 'Content Locale Plan',
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

    $user = User::query()->create([
        'name' => 'Content Locale User',
        'email' => 'content-locale-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return [$workspace, $site, $user];
}

it('stores new content and brief in the workspace default locale', function () {
    [, $site, $user] = createContentLocalePersistenceContext();

    $this->actingAs($user)
        ->post(route('app.content.store'), [
            'title' => 'Nederlandse content workflow',
            'site_id' => (string) $site->id,
        ])
        ->assertRedirect();

    $content = Content::query()->with('brief')->latest('created_at')->firstOrFail();

    expect($content->localeCode())->toBe('nl')
        ->and((string) ($content->brief?->language))->toBe('nl')
        ->and($content->translation_source_locale)->toBeNull()
        ->and((bool) $content->is_source_locale)->toBeTrue();
});

it('copies the brief locale onto generated drafts', function () {
    [$workspace, $site] = createContentLocalePersistenceContext();

    Queue::fake();

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Nederlandse generatie',
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => null,
        'is_source_locale' => true,
        'status' => 'brief',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Nederlandse brief',
        'language' => SupportedLanguage::NL->value,
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'client_refs' => [
            'taxonomy' => [
                'intent_keys' => ['nederlandse content'],
            ],
        ],
    ]);

    $draft = app(BriefToDraftService::class)->claimAndCreateDraft((string) $brief->id);

    expect($draft)->not->toBeNull()
        ->and($draft->language)->toBe(SupportedLanguage::NL)
        ->and((string) data_get($draft->meta, 'language'))->toBe('nl');
});
