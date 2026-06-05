<?php

require_once __DIR__ . '/ContentIntelligenceTestHelpers.php';

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Content\LocaleContentMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('maps locale variants from the same translation family', function () {
    [$workspace, $site] = makeContentIntelligenceContext('locale-family');

    $source = makeContentVariant($workspace, $site, 'Family source', 'en', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $dutch = makeContentVariant($workspace, $site, 'Family dutch', 'nl', [
        'translation_source_content_id' => $source->id,
        'translation_generated_at' => now()->subDays(5),
        'translation_source_updated_at' => now()->subDays(5),
        'is_source_locale' => false,
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $germanDraft = makeContentVariant($workspace, $site, 'Family german', 'de', [
        'translation_source_content_id' => $source->id,
        'translation_generated_at' => now(),
        'translation_source_updated_at' => now(),
        'is_source_locale' => false,
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);

    makeCurrentVersion($source, '<p>Fresh source body</p>', now());

    $service = app(LocaleContentMapService::class);
    $map = $service->map($dutch);

    expect($map->keys()->all())->toBe(['de', 'en', 'nl'])
        ->and((string) $service->source($dutch)->id)->toBe((string) $source->id)
        ->and((string) $service->variantForLocale($source, 'nl')?->id)->toBe((string) $dutch->id)
        ->and($service->variantForLocale($source, 'de', publishedOnly: true))->toBeNull()
        ->and($service->outdatedLocales($source))->toBe(['NL']);
});
