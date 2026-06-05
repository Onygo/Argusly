<?php

require_once __DIR__ . '/ContentIntelligenceTestHelpers.php';

use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\Workspace;
use App\View\Presenters\ContentIndexTreePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('normalizes duplicate locale rows to one badge per locale with deterministic source badges', function () {
    [$workspace, $site] = makeContentIntelligenceContext('content-index-tree');

    $workspace->update([
        'default_content_language' => SupportedLanguage::NL->value,
        'enabled_content_languages' => [SupportedLanguage::NL->value, SupportedLanguage::EN->value],
    ]);

    $source = makeContentVariant($workspace, $site, 'Canonical source', 'nl', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    $english = makeContentVariant($workspace, $site, 'English translation', 'en', [
        'family_id' => $source->id,
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);

    $duplicateDutch = new Content([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Broken duplicate dutch',
        'language' => 'nl',
        'family_id' => $source->id,
        'translation_source_content_id' => $english->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'translation',
    ]);
    $duplicateDutch->setRelation('workspace', $workspace);
    $duplicateDutch->setRelation('clientSite', $site);
    $duplicateDutch->created_at = now()->addMinute();
    $duplicateDutch->updated_at = now()->addMinute();

    $tree = ContentIndexTreePresenter::present(
        new Collection([$source, $english, $duplicateDutch]),
        new Collection([$source, $english, $duplicateDutch]),
    )->flatMap(fn (array $group): array => $group['articles'] ?? []);

    $article = $tree->first();

    expect($article)->not->toBeNull()
        ->and(collect($article['all_variants'])->pluck('locale')->all())->toBe(['NL', 'EN'])
        ->and(collect($article['all_variants'])->pluck('source_locale')->filter()->values()->all())->toBe(['NL']);
});

it('adds partial publication reasons and filtered group counts', function () {
    [$workspace, $site] = makeContentIntelligenceContext('content-index-tree-partial');

    $workspace->update([
        'default_content_language' => SupportedLanguage::EN->value,
        'enabled_content_languages' => [SupportedLanguage::EN->value, SupportedLanguage::NL->value],
    ]);

    $source = makeContentVariant($workspace, $site, 'Partial source', 'en', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    $tree = ContentIndexTreePresenter::present(
        new Collection([$source]),
        new Collection([$source]),
        ['publication_state' => 'partially_published']
    );

    $group = $tree->first();
    $article = collect($group['articles'] ?? [])->first();

    expect($group)->not->toBeNull()
        ->and(data_get($group, 'summary.visible_article_count'))->toBe(1)
        ->and(data_get($article, 'summary.status_label'))->toBe('Partially published')
        ->and(data_get($article, 'summary.status_tooltip'))->toContain('Missing locale')
        ->and(data_get($article, 'summary.status_reasons'))->toContain('Missing locale');
});
