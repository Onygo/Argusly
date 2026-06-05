<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentDestination;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('hides soft deleted content from the content index by default and shows it when requested', function () {
    [$user, $workspace, $site, $destination] = makeContentDeletionUiContext();

    $visible = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'content_destination_id' => (string) $destination->id,
        'title' => 'Visible article',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
    ]);

    $deleted = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'content_destination_id' => (string) $destination->id,
        'title' => 'Deleted article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
    ]);
    $deleted->delete();

    $this->actingAs($user)
        ->get(route('app.content.index'))
        ->assertOk()
        ->assertSee('Visible article')
        ->assertDontSee('Deleted article');

    $this->actingAs($user)
        ->get(route('app.content.index', ['show_deleted' => 1]))
        ->assertOk()
        ->assertSee('Visible article')
        ->assertSee('Deleted article')
        ->assertSee('Restore item');
});

it('soft deletes an entire family from the app action and can restore a single item', function () {
    [$user, $workspace, $site, $destination] = makeContentDeletionUiContext();

    $automation = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'Delete automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addDay(),
        'chain_size' => 1,
        'locale' => 'nl',
        'locales' => ['nl', 'en'],
        'topic_scope' => 'Delete scope',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $source = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'content_destination_id' => (string) $destination->id,
        'title' => 'Family source',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'automation_id' => (string) $automation->id,
        'source' => 'manual',
    ]);

    $variant = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'content_destination_id' => (string) $destination->id,
        'title' => 'Family variant',
        'language' => 'en',
        'family_id' => (string) $source->id,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
    ]);

    $this->actingAs($user)
        ->post(route('app.content.delete', $source->id), ['scope' => 'family'])
        ->assertRedirect()
        ->assertSessionHas('status', '2 content item(s) deleted.');

    expect(Content::withTrashed()->find($source->id)?->trashed())->toBeTrue()
        ->and(Content::withTrashed()->find($variant->id)?->trashed())->toBeTrue();

    $this->actingAs($user)
        ->post(route('app.content.restore', $variant->id))
        ->assertRedirect()
        ->assertSessionHas('status', 'Content restored.');

    expect(Content::find($variant->id))->not->toBeNull()
        ->and(Content::withTrashed()->find($source->id)?->trashed())->toBeTrue();
});

function makeContentDeletionUiContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Delete UI Org',
        'slug' => 'content-delete-ui-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Delete UI BV',
        'billing_address_line1' => 'Delete straat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Delete UI Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Delete UI Site',
        'site_url' => 'https://content-delete-ui.example.com',
        'base_url' => 'https://content-delete-ui.example.com',
        'allowed_domains' => ['content-delete-ui.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $destination = ContentDestination::query()->create([
        'workspace_id' => (string) $workspace->id,
        'name' => 'UI Destination',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'content-delete-ui-plan'],
        [
            'name' => 'Content Delete UI Plan',
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
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$user, $workspace, $site, $destination];
}
