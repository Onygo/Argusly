<?php

use App\Jobs\PageIntelligence\FetchMonitoredPageJob;
use App\Models\MarketPack;
use App\Models\MarketPackInstallation;
use App\Models\MarketPackKeyword;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\PageIntelligence\Discovery\MonitoredSourceUrlDiscoverer;
use App\Services\PageIntelligence\MarketPacks\MarketPackInstaller;
use Database\Seeders\MarketPackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function marketPackWorkspace(string $name = 'Market Pack Workspace'): Workspace
{
    $organization = Organization::query()->create([
        'name' => $name.' Organization',
        'slug' => str($name)->slug().'-'.str()->random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    return Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => $name,
        'display_name' => $name,
    ]);
}

it('installs the Automotive market pack for a workspace', function (): void {
    $this->seed(MarketPackSeeder::class);

    $workspace = marketPackWorkspace('Automotive Pack');
    $installation = app(MarketPackInstaller::class)->install($workspace, 'automotive');

    expect($installation)->toBeInstanceOf(MarketPackInstallation::class)
        ->and($installation->workspace_id)->toBe($workspace->id)
        ->and($installation->status)->toBe(MarketPackInstallation::STATUS_ACTIVE)
        ->and($installation->marketPack->key)->toBe('automotive');
});

it('installs the Telecom market pack for a workspace', function (): void {
    $this->seed(MarketPackSeeder::class);

    $workspace = marketPackWorkspace('Telecom Pack');
    $installation = app(MarketPackInstaller::class)->install($workspace, 'telecom');

    expect($installation->workspace_id)->toBe($workspace->id)
        ->and($installation->marketPack->key)->toBe('telecom')
        ->and($installation->installed_at)->not->toBeNull();
});

it('creates operational sources while keeping themes and competitors on the pack template', function (): void {
    $this->seed(MarketPackSeeder::class);

    $workspace = marketPackWorkspace('Automotive Operational');
    app(MarketPackInstaller::class)->install($workspace, 'automotive');

    $pack = MarketPack::query()->where('key', 'automotive')->firstOrFail();

    expect(MonitoredSource::query()->where('workspace_id', $workspace->id)->count())->toBeGreaterThanOrEqual(3)
        ->and($pack->themes()->count())->toBeGreaterThanOrEqual(3)
        ->and($pack->competitors()->count())->toBeGreaterThanOrEqual(3)
        ->and(MonitoredSource::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'Automotive demo watchlist')
            ->firstOrFail()
            ->metadata_json['market_pack_key'])->toBe('automotive');
});

it('stores workspace customization without modifying the global market pack template', function (): void {
    $this->seed(MarketPackSeeder::class);

    $workspace = marketPackWorkspace('Customized Automotive');
    $installation = app(MarketPackInstaller::class)->install($workspace, 'automotive', customizedConfig: [
        'keywords' => [
            'electric_vehicles' => ['solid-state battery partnerships'],
        ],
    ]);

    expect($installation->keyword_overrides_json['electric_vehicles'])->toBe(['solid-state battery partnerships'])
        ->and(MarketPackKeyword::query()->where('keyword', 'solid-state battery partnerships')->exists())->toBeFalse()
        ->and(MarketPack::query()->where('key', 'automotive')->firstOrFail()->keywords()->where('keyword', 'electric vehicle')->exists())->toBeTrue();
});

it('discovers monitored pages from installed market pack sources', function (): void {
    Queue::fake();
    $this->seed(MarketPackSeeder::class);

    $workspace = marketPackWorkspace('Discover Automotive');
    app(MarketPackInstaller::class)->install($workspace, 'automotive');

    $source = MonitoredSource::query()
        ->where('workspace_id', $workspace->id)
        ->where('name', 'Automotive demo watchlist')
        ->firstOrFail();

    $result = app(MonitoredSourceUrlDiscoverer::class)->discover($source);

    expect($result->successful)->toBeTrue()
        ->and($result->created)->toBe(1)
        ->and(MonitoredPage::query()
            ->where('workspace_id', $workspace->id)
            ->where('monitored_source_id', $source->id)
            ->where('canonical_url', 'https://example.com/automotive/ev-launch')
            ->exists())->toBeTrue();

    Queue::assertPushed(FetchMonitoredPageJob::class);
});
