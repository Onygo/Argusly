<?php

require_once __DIR__ . '/../../Support/LocalizationAgentTestHelpers.php';

use App\Agents\Data\AgentContext;
use App\Agents\Localization\LocalizationAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detects a locale mismatch when a draft is labeled english but reads as dutch', function () {
    [$owner, $workspace, $site] = makeLocalizationAgentContext('localization-draft-mismatch');

    $content = makeLocalizedContent($workspace, $site, $owner, 'English labeled article', 'en');
    $draft = makeLocalizedDraft(
        $content,
        $site,
        'Nederlandse handleiding',
        'en',
        '<p>Dit is een Nederlandse gids en deze uitleg is voor teams in Nederland.</p>'
    );

    $result = app(LocalizationAgent::class)->run(AgentContext::forDraft($draft));

    expect(collect($result->suggestions)->pluck('key')->all())->toContain('draft_locale_mismatch');
});

it('detects when a translated content variant is out of sync with the source', function () {
    [$owner, $workspace, $site] = makeLocalizationAgentContext('localization-outdated-content');

    $source = makeLocalizedContent($workspace, $site, $owner, 'Source article', 'en');
    attachLocalizedVersion($source, '<p>Fresh English source body.</p>', now());

    $translation = makeLocalizedContent($workspace, $site, $owner, 'Nederlandse versie', 'nl', [
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'translation_generated_at' => now()->subDays(10),
        'translation_source_updated_at' => now()->subDays(10),
    ]);
    attachLocalizedVersion($translation, '<p>Oudere Nederlandse body.</p>', now()->subDays(10));

    $result = app(LocalizationAgent::class)->run(AgentContext::forContent($source));

    expect(collect($result->suggestions)->pluck('key')->all())->toContain('translation_out_of_sync');
});

it('detects missing translation opportunities for enabled locales', function () {
    [$owner, $workspace, $site] = makeLocalizationAgentContext('localization-missing-translation');

    $source = makeLocalizedContent($workspace, $site, $owner, 'Source article', 'en');
    attachLocalizedVersion($source, '<p>Fresh English source body.</p>', now());

    $result = app(LocalizationAgent::class)->run(AgentContext::forContent($source));

    expect(collect($result->suggestions)->pluck('key')->all())->toContain('missing_translation')
        ->and(collect($result->suggestions)->pluck('actions')->flatten(1)->pluck('target_locale')->all())->toContain('de');
});

it('detects locale mismatches for additional supported languages', function (string $locale, string $title, string $body) {
    [$owner, $workspace, $site] = makeLocalizationAgentContext('localization-extra-'.$locale);

    $content = makeLocalizedContent($workspace, $site, $owner, 'English labeled article', 'en');
    $draft = makeLocalizedDraft($content, $site, $title, 'en', $body);

    $result = app(LocalizationAgent::class)->run(AgentContext::forDraft($draft));

    expect(collect($result->suggestions)->pluck('key')->all())->toContain('draft_locale_mismatch');
})->with([
    'german' => [
        'locale' => 'de',
        'title' => 'Deutsche Anleitung',
        'body' => '<p>Dies ist eine deutsche Anleitung und diese Erklärung hilft Teams beim Aufbau von Workflows.</p>',
    ],
    'french' => [
        'locale' => 'fr',
        'title' => 'Guide français',
        'body' => '<p>Ceci est un guide français et cette explication aide les équipes avec le flux éditorial.</p>',
    ],
    'spanish' => [
        'locale' => 'es',
        'title' => 'Guía española',
        'body' => '<p>Esta es una guía en español y este manual ayuda a los equipos con el flujo editorial.</p>',
    ],
]);

it('detects localized metadata completeness gaps and missing slugs', function () {
    [$owner, $workspace, $site] = makeLocalizationAgentContext('localization-metadata-gaps');

    $source = makeLocalizedContent($workspace, $site, $owner, 'Source article', 'en');
    attachLocalizedVersion($source, '<p>Fresh English source body.</p>', now());

    $translation = makeLocalizedContent($workspace, $site, $owner, 'Nederlandse versie', 'nl', [
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'seo_meta_description' => null,
        'publish_url_key' => null,
    ]);
    attachLocalizedVersion($translation, '<p>Localized body.</p>', now()->subDays(2));

    $result = app(LocalizationAgent::class)->run(AgentContext::forContent($source));
    $keys = collect($result->suggestions)->pluck('key')->all();

    expect($keys)->toContain('localized_metadata_completeness')
        ->and($keys)->toContain('localized_slug_missing');
});
