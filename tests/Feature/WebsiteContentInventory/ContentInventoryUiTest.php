<?php

use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('shows content inventory and supports refresh exclude and activation actions', function (): void {
    Queue::fake();
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
    [$workspace, $user, $page] = contentInventoryUiContext();
    $content = Content::factory()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $page->client_site_id,
        'title' => 'Existing inventory asset',
        'published_url' => 'https://example.com/inventory-ui',
    ]);

    $this->actingAs($user)
        ->get(route('app.page-intelligence.index', ['workspace' => $workspace->id, 'tab' => 'content-inventory']))
        ->assertOk()
        ->assertSee('Content Inventory')
        ->assertSee('Inventory UI Page')
        ->assertSee('Existing inventory asset')
        ->assertSee('Activate');

    $this->actingAs($user)
        ->post(route('app.page-intelligence.content-inventory.refresh', $page))
        ->assertSessionHas('status');

    Queue::assertPushed(FetchMonitoredPageJob::class, fn (FetchMonitoredPageJob $job): bool => $job->monitoredPageId === $page->id);

    $this->actingAs($user)
        ->post(route('app.page-intelligence.content-inventory.exclude', $page))
        ->assertSessionHas('status');

    expect(data_get($page->refresh()->metadata_json, 'inventory.review_override'))->toBe('excluded');

    $this->actingAs($user)
        ->post(route('app.page-intelligence.content-inventory.include', $page))
        ->assertSessionHas('status');

    $this->actingAs($user)
        ->post(route('app.page-intelligence.content-inventory.link-content', $page), ['content_id' => $content->id])
        ->assertSessionHas('status');

    $link = ContentPageLink::query()
        ->where('monitored_page_id', $page->id)
        ->where('content_id', $content->id)
        ->first();

    expect($link)->not->toBeNull()
        ->and(data_get($link?->metadata, 'linked_from'))->toBe('content_inventory');

    $this->actingAs($user)
        ->post(route('app.page-intelligence.content-inventory.link-content', $page), ['content_id' => $content->id])
        ->assertSessionHas('status');

    expect(ContentPageLink::query()->where('monitored_page_id', $page->id)->count())->toBe(1);

    $this->actingAs($user)
        ->post(route('app.page-intelligence.content-inventory.activate', $page))
        ->assertRedirect();

    expect(ContentPageLink::query()->where('monitored_page_id', $page->id)->count())->toBe(1);

    $this->actingAs($user)
        ->post(route('app.page-intelligence.content-inventory.activate', $page))
        ->assertRedirect();

    expect(ContentPageLink::query()->where('monitored_page_id', $page->id)->count())->toBe(1);
});

function contentInventoryUiContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Inventory UI',
        'slug' => 'content-inventory-ui',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Content Inventory UI Workspace',
        'display_name' => 'Content Inventory UI Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Example',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'active',
    ]);

    $source = MonitoredSource::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'source_type' => 'analytics_observed',
        'domain' => 'example.com',
        'base_url' => 'https://example.com',
    ]);

    $page = MonitoredPage::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_source_id' => $source->id,
        'canonical_url' => 'https://example.com/inventory-ui',
        'canonical_url_hash' => hash('sha256', 'https://example.com/inventory-ui'),
        'first_seen_url' => 'https://example.com/inventory-ui',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/inventory-ui'),
        'final_url' => 'https://example.com/inventory-ui',
        'final_url_hash' => hash('sha256', 'https://example.com/inventory-ui'),
        'domain' => 'example.com',
        'path' => '/inventory-ui',
        'source_type' => 'analytics_observed',
        'page_type' => 'page',
        'title_current' => 'Inventory UI Page',
        'indexability_status' => 'indexable',
    ]);

    $snapshot = PageSnapshot::factory()->forPage($page)->create([
        'http_status' => 200,
        'content_changed' => false,
    ]);

    PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'title' => 'Inventory UI Page',
        'meta_description' => 'Inventory UI description.',
        'h1' => 'Inventory UI Page',
        'open_graph_image_url' => 'https://example.com/og.jpg',
        'schema_types_json' => ['WebPage'],
        'canonical_url' => 'https://example.com/inventory-ui',
        'content_fingerprint' => hash('sha256', 'Inventory UI Page'),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return [$workspace, $user, $page];
}
