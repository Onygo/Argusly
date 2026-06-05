<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentSeries;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('loads the recently changed content pages for an authenticated tenant user', function () {
    [$user, $site, $content, $automation, $series] = makeRecentContentSmokeContext();

    $this->actingAs($user)
        ->get(route('app.content.index'))
        ->assertOk()
        ->assertSee('Source smoke article')
        ->assertSee('Dutch smoke translation');

    $this->actingAs($user)
        ->get(route('app.content.index', [
            'locale' => 'nl',
            'automation' => (string) $automation->id,
            'series' => (string) $series->id,
            'status' => 'draft',
        ]))
        ->assertOk()
        ->assertSee('Source smoke article')
        ->assertSee('Smoke Automation')
        ->assertSee('Smoke Series');

    $this->actingAs($user)
        ->get(route('app.content.lifecycle.index'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('app.content.create'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('app.content.show', $content))
        ->assertOk()
        ->assertSee('Source smoke article');

    $this->actingAs($user)
        ->getJson(route('app.content.improvements.status', $content))
        ->assertOk()
        ->assertJsonStructure(['actions_html', 'monitor_html', 'generated_html', 'events', 'latest_event_id']);

    $this->actingAs($user)
        ->get(route('app.content.automations.index'))
        ->assertOk()
        ->assertSee('Smoke Automation');

    $this->actingAs($user)
        ->get(route('app.content.automations.show', $automation))
        ->assertOk()
        ->assertSee('Smoke Automation');

    $this->actingAs($user)
        ->get(route('app.content.series.index'))
        ->assertOk()
        ->assertSee('Smoke Series');

    $this->actingAs($user)
        ->get(route('app.content.series.show', $series))
        ->assertOk()
        ->assertSee('Smoke Series');

    $this->actingAs($user)
        ->get(route('app.content.series.structure', $series))
        ->assertOk()
        ->assertSee('Smoke Series');
});

function makeRecentContentSmokeContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Recent Content Smoke Org',
        'slug' => 'recent-content-smoke-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Recent Content Smoke BV',
        'billing_address_line1' => 'Smoke Street 10',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Recent Content Smoke Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Recent Content Smoke Site',
        'site_url' => 'https://recent-content-smoke.example.com',
        'base_url' => 'https://recent-content-smoke.example.com',
        'allowed_domains' => ['recent-content-smoke.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'recent-content-smoke-plan'],
        [
            'name' => 'Recent Content Smoke Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    $subscription = Subscription::query()->create([
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

    $organization->forceFill([
        'active_subscription_id' => $subscription->id,
    ])->save();

    $user = User::query()->create([
        'name' => 'Recent Content Smoke User',
        'email' => 'recent-content-smoke-' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $automation = ContentAutomation::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Smoke Automation',
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addDay(),
        'chain_size' => 3,
        'locale' => 'en',
        'locales' => ['en', 'nl'],
        'topic_scope' => 'Smoke topic scope',
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $series = ContentSeries::query()->create([
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Smoke Series',
        'main_topic' => 'Smoke main topic',
        'primary_keyword' => 'smoke keyword',
        'supporting_keywords' => ['smoke support'],
        'articles_count' => 3,
        'status' => ContentSeries::STATUS_DRAFT,
        'created_by' => $user->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Source smoke article',
        'language' => 'en',
        'translation_source_locale' => 'en',
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'delivery_status' => 'pending',
        'source' => 'manual',
        'automation_id' => $automation->id,
        'series_id' => $series->id,
        'primary_keyword' => 'smoke keyword',
        'lifecycle_stage' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => ContentVersion::TYPE_REVISION,
        'body' => '<p>Smoke body content.</p>',
        'source' => ContentVersion::SOURCE_PUBLISHLAYER,
        'created_by' => $user->id,
    ]);

    $content->forceFill([
        'current_version_id' => (string) $version->id,
    ])->save();

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Dutch smoke translation',
        'language' => 'nl',
        'translation_source_locale' => 'en',
        'translation_source_content_id' => (string) $content->id,
        'family_id' => (string) $content->id,
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
        'source' => 'translation',
        'automation_id' => $automation->id,
        'series_id' => $series->id,
        'primary_keyword' => 'rookwoord sleutelwoord',
        'lifecycle_stage' => 'review',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return [$user, $site, $content->fresh(), $automation, $series];
}
