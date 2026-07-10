<?php

use App\Enums\ContentPageLinkType;
use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('reports promotion candidates in dry-run without creating content', function (): void {
    Queue::fake();

    MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/blog/dry-run',
        'canonical_url_hash' => hash('sha256', 'https://example.com/blog/dry-run'),
    ]);

    $this->artisan('website-content:backfill-inventory --dry-run')
        ->expectsOutputToContain('Mode: dry-run')
        ->expectsOutputToContain('Promotion: disabled')
        ->expectsOutputToContain('No monitored pages were promoted.')
        ->assertExitCode(0);

    expect(Content::query()->count())->toBe(0)
        ->and(ContentPageLink::query()->count())->toBe(0);
});

it('promotes eligible monitored pages only when explicitly requested', function (): void {
    Queue::fake();

    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/blog/promote',
        'canonical_url_hash' => hash('sha256', 'https://example.com/blog/promote'),
        'first_seen_url' => 'https://example.com/blog/promote?utm_source=tracking',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/blog/promote?utm_source=tracking'),
    ]);

    $this->artisan('website-content:backfill-inventory --promote')
        ->expectsOutputToContain('Promotion: enabled')
        ->assertExitCode(0);

    expect(Content::query()->count())->toBe(1)
        ->and(ContentPageLink::query()->where('link_type', ContentPageLinkType::OBSERVED_SOURCE->value)->count())->toBe(1);
});

it('links existing content publication URLs to monitored pages without promotion', function (): void {
    Queue::fake();

    $page = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/blog/existing',
        'canonical_url_hash' => hash('sha256', 'https://example.com/blog/existing'),
        'first_seen_url' => 'https://example.com/blog/existing',
        'first_seen_url_hash' => hash('sha256', 'https://example.com/blog/existing'),
        'final_url' => 'https://example.com/blog/existing',
        'final_url_hash' => hash('sha256', 'https://example.com/blog/existing'),
    ]);

    Content::factory()->create([
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $page->client_site_id,
        'published_url' => 'https://example.com/blog/existing?utm_source=newsletter',
    ]);

    $this->artisan('website-content:backfill-inventory')
        ->assertExitCode(0);

    expect(Content::query()->whereNotNull('url_hash')->count())->toBe(1)
        ->and(ContentPageLink::query()->where('link_type', ContentPageLinkType::PUBLICATION_URL->value)->count())->toBe(1);
});

it('can resume monitored page processing after a UUID', function (): void {
    Queue::fake();

    $first = MonitoredPage::factory()->create(['canonical_url' => 'https://example.com/blog/first']);
    $second = MonitoredPage::factory()->create(['canonical_url' => 'https://example.com/blog/second']);

    $resumeAfter = collect([(string) $first->id, (string) $second->id])->sort()->first();

    $this->artisan('website-content:backfill-inventory --dry-run --resume-after='.$resumeAfter)
        ->assertExitCode(0);

    expect(Content::query()->count())->toBe(0);
});
