<?php

use App\Enums\ContentInventorySourceType;
use App\Enums\ContentReviewStatus;
use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use App\Models\Organization;
use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;
use App\Models\User;
use App\Services\WebsiteContentInventory\WebsiteContentActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('promotes a monitored page to content using extraction metadata only', function (): void {
    Queue::fake();

    $page = MonitoredPage::factory()->create([
        'source_type' => 'xml_sitemap',
        'page_type' => 'article',
        'canonical_url' => 'https://example.com/blog/launch?page=2',
        'canonical_url_hash' => hash('sha256', 'https://example.com/blog/launch?page=2'),
        'first_seen_url' => 'https://example.com/blog/launch?page=2&utm_source=sitemap',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/blog/launch?page=2&utm_source=sitemap'),
        'final_url' => 'https://example.com/blog/launch?page=2',
        'final_url_hash' => hash('sha256', 'https://example.com/blog/launch?page=2'),
        'path' => '/blog/launch',
        'title_current' => 'Launch update',
        'language_current' => 'en',
    ]);
    $snapshot = PageSnapshot::factory()->forPage($page)->create([
        'http_status' => 200,
        'raw_html' => '<html><body>raw html must remain in Page Intelligence</body></html>',
        'raw_html_hash' => hash('sha256', 'raw html'),
        'text_hash' => hash('sha256', 'launch text'),
    ]);
    PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'title' => 'Argusly Launch Update',
        'meta_description' => 'A launch update from the existing website.',
        'h1' => 'Launch Update',
        'summary' => 'Short extraction summary.',
        'main_html' => '<main>do not copy this html</main>',
        'main_text' => 'Do not copy the full text body either.',
        'main_text_hash' => hash('sha256', 'main text fingerprint'),
        'metadata_json' => ['open_graph' => ['image' => 'https://example.com/og.jpg']],
        'structured_data_json' => [['@type' => 'Article']],
    ]);

    $result = app(WebsiteContentActivationService::class)->promote($page);
    $content = $result->content;

    expect($result->contentCreated)->toBeTrue()
        ->and($result->linkCreated)->toBeTrue()
        ->and($content->title)->toBe('Argusly Launch Update')
        ->and($content->inventory_source_type)->toBe(ContentInventorySourceType::SITEMAP_DISCOVERED)
        ->and($content->review_status)->toBe(ContentReviewStatus::PENDING_REVIEW)
        ->and($content->campaign_eligible)->toBeTrue()
        ->and($content->normalized_url)->toBe('https://example.com/blog/launch?page=2')
        ->and($content->seo_meta_description)->toBe('A launch update from the existing website.')
        ->and($content->seo_og_image)->toBe('https://example.com/og.jpg')
        ->and($content->schema_type)->toBe('Article')
        ->and(data_get($content->inventory_metadata, 'summary'))->toBe('Short extraction summary.')
        ->and(json_encode($content->getAttributes()))->not->toContain('do not copy this html')
        ->and(ContentPageLink::query()->count())->toBe(1);
});

it('is idempotent for repeated promotion', function (): void {
    Queue::fake();

    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/blog/idempotent',
        'canonical_url_hash' => hash('sha256', 'https://example.com/blog/idempotent'),
        'first_seen_url' => 'https://example.com/blog/idempotent?utm_source=tracking',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/blog/idempotent?utm_source=tracking'),
    ]);
    $snapshot = PageSnapshot::factory()->forPage($page)->create(['http_status' => 200]);
    PageContentExtraction::factory()->forSnapshot($snapshot)->create(['title' => 'Stable page']);

    $service = app(WebsiteContentActivationService::class);
    $first = $service->promote($page);
    $second = $service->promote($page);

    expect($first->contentCreated)->toBeTrue()
        ->and($second->contentCreated)->toBeFalse()
        ->and($second->linkCreated)->toBeFalse()
        ->and(Content::query()->count())->toBe(1)
        ->and(ContentPageLink::query()->count())->toBe(1);
});

it('prevents bridge links across workspaces', function (): void {
    Queue::fake();

    $content = Content::factory()->create();
    $page = MonitoredPage::factory()->create();

    expect(fn () => ContentPageLink::factory()->forContentAndPage($content, $page)->create())
        ->toThrow(InvalidArgumentException::class, 'workspace');
});

it('authorizes content page links by organization and role', function (): void {
    Queue::fake();

    $link = ContentPageLink::factory()->create();
    $organizationId = (int) $link->workspace->organization_id;
    $viewer = User::factory()->create(['organization_id' => $organizationId, 'role' => 'viewer']);
    $editor = User::factory()->create(['organization_id' => $organizationId, 'role' => 'editor']);
    $otherOrganization = Organization::query()->create([
        'name' => 'Other Inventory Org',
        'slug' => 'other-inventory-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);
    $otherUser = User::factory()->create(['organization_id' => $otherOrganization->id, 'role' => 'editor']);

    expect(Gate::forUser($viewer)->allows('view', $link))->toBeTrue()
        ->and(Gate::forUser($viewer)->allows('update', $link))->toBeFalse()
        ->and(Gate::forUser($editor)->allows('update', $link))->toBeTrue()
        ->and(Gate::forUser($otherUser)->allows('view', $link))->toBeFalse();
});

it('keeps existing campaign-facing content behavior intact', function (): void {
    Queue::fake();

    $content = Content::factory()->create([
        'title' => 'Existing managed content',
        'published_url' => 'https://example.com/existing-managed-content',
    ]);

    expect($content->campaignContents()->count())->toBe(0)
        ->and($content->socialPostVariants()->count())->toBe(0);
});
