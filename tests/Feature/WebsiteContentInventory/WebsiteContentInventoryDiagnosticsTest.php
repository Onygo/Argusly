<?php

use App\Models\Content;
use App\Models\MonitoredPage;
use App\Services\WebsiteContentInventory\WebsiteContentActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('shows website content inventory diagnostics', function (): void {
    Queue::fake();

    $eligible = MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/blog/diagnostics',
        'canonical_url_hash' => hash('sha256', 'https://example.com/blog/diagnostics'),
    ]);
    app(WebsiteContentActivationService::class)->promote($eligible);

    MonitoredPage::factory()->create([
        'canonical_url' => 'https://example.com/login',
        'canonical_url_hash' => hash('sha256', 'https://example.com/login'),
        'path' => '/login',
    ]);

    Content::factory()->create([
        'inventory_source_type' => 'argusly_managed',
        'published_url' => 'https://example.com/orphan-content',
    ]);

    $this->artisan('argusly:diagnostics')
        ->expectsOutputToContain('inventory.linked_monitored_pages')
        ->expectsOutputToContain('inventory.promoted_assets')
        ->expectsOutputToContain('inventory.orphan_monitored_pages')
        ->expectsOutputToContain('inventory.orphan_content')
        ->expectsOutputToContain('inventory.eligibility.eligible')
        ->assertExitCode(0);
});
