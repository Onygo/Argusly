<?php

use App\Jobs\ContentAutomation\RunContentAutomationJob;
use App\Jobs\GenerateBatchItemBriefJob;
use App\Jobs\GenerateSeriesRunArticleJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentPublication;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Content\ContentDeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates one generated content row for the same stable automation item', function () {
    Log::spy();
    [$workspace, $site] = makeContentDedupeContext();
    $service = app(ContentDeduplicationService::class);

    $payload = [
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Duplicate-safe automation article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'external_key' => 'automation-1-item-1',
    ];

    $scope = [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'automation_id' => 'automation-1',
        'item_key' => 'item-1',
        'language' => 'en',
        'type' => 'article',
        'external_key' => 'automation-1-item-1',
    ];

    $first = $service->createOrReuse($payload, $scope);
    $second = $service->createOrReuse(array_merge($payload, [
        'id' => (string) Str::uuid(),
        'title' => 'Duplicate-safe automation article retry',
    ]), $scope);

    expect((string) $second->id)->toBe((string) $first->id)
        ->and(Content::query()->where('workspace_id', $workspace->id)->where('external_key', 'automation-1-item-1')->count())->toBe(1)
        ->and($second->getAttribute('dedupe_was_reused'))->toBeTrue()
        ->and($second->fresh()->dedupe_was_reused)->toBeTrue()
        ->and($second->fresh()->dedupe_reused_at)->not->toBeNull()
        ->and($second->fresh()->dedupe_reuse_reason)->toBe('fingerprint_match');

    Log::shouldHaveReceived('notice')
        ->with('content.dedupe_duplicate_prevented', Mockery::type('array'))
        ->once();
});

it('keeps separate automation chain slots distinct even with the same title and keyword', function () {
    [$workspace, $site] = makeContentDedupeContext();
    $service = app(ContentDeduplicationService::class);
    $automation = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Distinct chain slot automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 1,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addDay(),
        'chain_size' => 2,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Distinct chain slot topic',
    ]);
    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $workspace->organization_id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'status' => 'running',
        'triggered_by' => 'manual',
        'started_at' => now(),
        'metadata' => [],
    ]);

    $basePayload = [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Same chain topic',
        'language' => 'en',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'automation_id' => (string) $automation->id,
        'automation_run_id' => (string) $run->id,
        'primary_keyword' => 'same chain keyword',
    ];
    $baseScope = [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'automation_id' => (string) $automation->id,
        'automation_run_id' => (string) $run->id,
        'language' => 'en',
        'type' => 'article',
        'primary_keyword' => 'same chain keyword',
        'title' => 'Same chain topic',
    ];

    $first = $service->createOrReuse(array_merge($basePayload, [
        'id' => (string) Str::uuid(),
        'external_key' => 'automation-1-item-1',
    ]), array_merge($baseScope, [
        'external_key' => 'automation-1-item-1',
    ]));
    $second = $service->createOrReuse(array_merge($basePayload, [
        'id' => (string) Str::uuid(),
        'external_key' => 'automation-1-item-2',
    ]), array_merge($baseScope, [
        'external_key' => 'automation-1-item-2',
    ]));

    expect((string) $second->id)->not->toBe((string) $first->id)
        ->and(Content::query()->where('automation_run_id', (string) $run->id)->count())->toBe(2)
        ->and($second->getAttribute('dedupe_was_reused'))->not->toBeTrue();
});

it('reuses automation content across reruns when title locale and keyword match', function () {
    [$workspace, $site] = makeContentDedupeContext();
    $service = app(ContentDeduplicationService::class);
    $automation = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Deduplication automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 1,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addDay(),
        'chain_size' => 3,
        'locale' => 'nl',
        'locales' => ['nl', 'en'],
        'topic_scope' => 'Deduplication topic',
    ]);

    $first = $service->createOrReuse([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Automation intent article',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'automation_id' => (string) $automation->id,
        'primary_keyword' => 'automation intent',
        'external_key' => 'automation-1-run-1-item-1',
    ], [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'automation_id' => (string) $automation->id,
        'language' => 'nl',
        'type' => 'article',
        'primary_keyword' => 'automation intent',
        'title' => 'Automation intent article',
    ]);

    $second = $service->createOrReuse([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Automation intent article',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'automation_id' => (string) $automation->id,
        'primary_keyword' => 'automation intent',
        'external_key' => 'automation-1-run-2-item-9',
    ], [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'automation_id' => (string) $automation->id,
        'language' => 'nl',
        'type' => 'article',
        'primary_keyword' => 'automation intent',
        'title' => 'Automation intent article',
    ]);

    expect((string) $second->id)->toBe((string) $first->id)
        ->and(Content::query()->where('automation_id', $automation->id)->count())->toBe(1)
        ->and($second->getAttribute('dedupe_was_reused'))->toBeTrue();
});

it('keeps reused automation content saveable by later generation updates', function () {
    [$workspace, $site] = makeContentDedupeContext();
    $service = app(ContentDeduplicationService::class);
    $automation = ContentAutomation::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Saveable dedupe automation',
        'is_active' => true,
        'mode' => 'chain',
        'publication_mode' => 'draft_only',
        'generation_frequency_value' => 1,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->addDay(),
        'chain_size' => 1,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Saveable dedupe topic',
    ]);
    $scope = [
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'automation_id' => (string) $automation->id,
        'language' => 'en',
        'type' => 'article',
        'primary_keyword' => 'saveable automation reuse',
        'title' => 'Saveable automation reuse article',
    ];

    $first = $service->createOrReuse([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Saveable automation reuse article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'automation_id' => (string) $automation->id,
        'primary_keyword' => 'saveable automation reuse',
    ], $scope);

    $reused = $service->createOrReuse([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Saveable automation reuse article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'automation_id' => (string) $automation->id,
        'primary_keyword' => 'saveable automation reuse',
    ], $scope);

    $reused->forceFill([
        'status' => 'draft',
        'publish_status' => 'draft',
    ])->save();

    expect((string) $reused->id)->toBe((string) $first->id)
        ->and($reused->fresh()->status)->toBe('draft')
        ->and($reused->fresh()->dedupe_was_reused)->toBeTrue()
        ->and($reused->fresh()->dedupe_reuse_reason)->toBe('fingerprint_match');
});

it('enforces generated content uniqueness at the database level', function () {
    [$workspace, $site] = makeContentDedupeContext();
    $fingerprint = hash('sha256', 'same-generated-item');

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'First generated row',
        'language' => 'en',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'dedupe_fingerprint' => $fingerprint,
    ]);

    expect(fn () => Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Second generated row',
        'language' => 'en',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'automation',
        'dedupe_fingerprint' => $fingerprint,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('generated content jobs expose overlap locks for retry safety', function () {
    expect((new RunContentAutomationJob('automation-1'))->middleware())->toHaveCount(1)
        ->and((new GenerateBatchItemBriefJob('batch-item-1'))->middleware())->toHaveCount(1)
        ->and((new GenerateSeriesRunArticleJob('run-article-1'))->middleware())->toHaveCount(1);
});

it('resolves the same destination publication instead of creating duplicates on retry', function () {
    [$workspace, $site] = makeContentDedupeContext();
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Publication retry article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'automation',
    ]);

    $first = ContentPublication::resolveForDelivery(
        contentId: (string) $content->id,
        clientSiteId: (string) $site->id,
        provider: ContentPublication::PROVIDER_WORDPRESS,
        locale: 'en',
    );

    $first->markDelivered('wp-123', 'https://example.test/post');

    $second = ContentPublication::resolveForDelivery(
        contentId: (string) $content->id,
        clientSiteId: (string) $site->id,
        provider: ContentPublication::PROVIDER_WORDPRESS,
        locale: 'en',
    );

    expect((string) $second->id)->toBe((string) $first->id)
        ->and(ContentPublication::query()->where('content_id', $content->id)->count())->toBe(1)
        ->and((string) $second->remote_id)->toBe('wp-123');
});

it('shows duplicate groups in dry-run and prefers published content as canonical', function () {
    [$workspace, $site] = makeContentDedupeContext();
    $service = app(ContentDeduplicationService::class);

    $olderDraft = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Duplicate command article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'automation',
        'primary_keyword' => 'duplicate command keyword',
        'created_at' => now()->subMinutes(40),
        'updated_at' => now()->subMinutes(40),
    ]);

    $published = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Duplicate command article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'first_published_at' => now()->subMinutes(5),
        'source' => 'automation',
        'primary_keyword' => 'duplicate command keyword',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $published->id,
        'client_site_id' => (string) $site->id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'delivery_status' => 'delivered',
        'publication_status' => 'published',
        'remote_id' => 'wp-999',
        'remote_url' => 'https://dedupe.example.test/articles/duplicate-command-article',
    ]);

    $groups = $service->detectDuplicateGroups();

    expect($groups)->toHaveCount(1)
        ->and((string) data_get($groups->first(), 'canonical_id'))->toBe((string) $published->id)
        ->and(data_get($groups->first(), 'duplicate_ids'))->toContain((string) $olderDraft->id);

    $this->artisan('content:deduplicate --dry-run')
        ->expectsOutputToContain('Dry run only.')
        ->assertExitCode(0);

    expect($olderDraft->fresh()?->deleted_at)->toBeNull()
        ->and($olderDraft->fresh()?->duplicate_of_content_id)->toBeNull();
});

it('soft deletes duplicate rows when executed', function () {
    [$workspace, $site] = makeContentDedupeContext();

    $canonical = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Execute duplicate cleanup article',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'automation',
        'primary_keyword' => 'cleanup keyword',
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    $duplicate = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Execute duplicate cleanup article',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'automation',
        'primary_keyword' => 'cleanup keyword',
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    $this->artisan('content:deduplicate --execute')
        ->expectsOutputToContain('Soft deleted 1 duplicate content row(s).')
        ->assertExitCode(0);

    $active = Content::query()->get();
    $trashed = Content::query()->onlyTrashed()->get();

    expect($active)->toHaveCount(1)
        ->and($trashed)->toHaveCount(1)
        ->and($trashed->first()?->trashed())->toBeTrue()
        ->and((string) $trashed->first()?->duplicate_of_content_id)->toBe((string) $active->first()?->id);
});

it('can detect exact title duplicates across days and delete duplicate families', function () {
    [$workspace, $site] = makeContentDedupeContext();

    $canonical = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'From SEO to AI Visibility: A Practical Guide',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'automation',
        'origin_type' => 'chained_via_automation',
        'first_published_at' => now()->subDays(2),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    $canonical->forceFill(['family_id' => (string) $canonical->id])->save();

    $duplicate = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'From SEO to AI Visibility: A Practical Guide',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'automation',
        'origin_type' => 'chained_via_automation',
        'first_published_at' => now()->subDay(),
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);
    $duplicate->forceFill(['family_id' => (string) $duplicate->id])->save();

    $duplicateTranslation = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Van SEO naar AI-zichtbaarheid: Een praktische gids',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'translation',
        'family_id' => (string) $duplicate->id,
        'translation_source_content_id' => (string) $duplicate->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $groups = app(ContentDeduplicationService::class)->detectDuplicateGroups(
        limit: 10,
        windowMinutes: 60,
        exactTitle: true
    );

    expect($groups)->toHaveCount(1)
        ->and((string) data_get($groups->first(), 'canonical_id'))->toBe((string) $canonical->id)
        ->and(data_get($groups->first(), 'duplicate_ids'))->toContain((string) $duplicate->id);

    $this->artisan('content:deduplicate --execute --exact-title --families --title="From SEO to AI Visibility"')
        ->expectsOutputToContain('Soft deleted 2 duplicate content row(s).')
        ->assertExitCode(0);

    expect($canonical->fresh()?->trashed())->toBeFalse()
        ->and(Content::query()->onlyTrashed()->whereKey($duplicate->id)->exists())->toBeTrue()
        ->and(Content::query()->onlyTrashed()->whereKey($duplicateTranslation->id)->exists())->toBeTrue();
});

it('detects exact and near-title risks for same site and locale content', function () {
    [$workspace, $site] = makeContentDedupeContext();

    $source = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'From SEO to AI Visibility: A Practical Guide',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'From SEO to AI Visibility: A Practical Guide',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'From SEO to AI Visibility: A Practical GEO Guide',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
    ]);

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Van SEO naar AI-zichtbaarheid: Een praktische gids',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
    ]);

    $risks = app(ContentDeduplicationService::class)->titleSimilarityRisks($source);

    expect($risks)->toHaveCount(2)
        ->and(collect($risks)->pluck('match_type')->all())->toContain('exact_title', 'similar_title')
        ->and(collect($risks)->pluck('title')->all())->not->toContain('Van SEO naar AI-zichtbaarheid: Een praktische gids');
});

function makeContentDedupeContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Dedupe Org',
        'slug' => 'content-dedupe-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Dedupe Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Dedupe Site',
        'site_url' => 'https://dedupe.example.test',
        'base_url' => 'https://dedupe.example.test',
        'allowed_domains' => ['dedupe.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site];
}
