<?php

use App\Enums\ContentSource;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\TitleSanitizer;

it('normalizes automation content payloads before persistence', function () {
    $payload = ContentPersistencePayloadNormalizer::normalize([
        'source' => 'chained_content',
        'title' => "  <h1>" . str_repeat('Long generated title ', 30) . "</h1>  ",
        'external_key' => str_repeat('external-', 40),
        'publish_url_key' => str_repeat('publish-', 100),
        'canonical_url_key' => str_repeat('canonical-', 100),
        'language' => 'en_US',
        'translation_source_locale' => 'nl_NL',
    ]);

    expect($payload['source'])->toBe(ContentSource::AUTOMATION->value)
        ->and(mb_strlen($payload['title']))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and($payload['title'])->toContain('Long generated title')
        ->and(mb_strlen((string) $payload['external_key']))->toBeLessThanOrEqual(ContentPersistencePayloadNormalizer::EXTERNAL_KEY_MAX_LENGTH)
        ->and(mb_strlen((string) $payload['publish_url_key']))->toBeLessThanOrEqual(ContentPersistencePayloadNormalizer::URL_KEY_MAX_LENGTH)
        ->and(mb_strlen((string) $payload['canonical_url_key']))->toBeLessThanOrEqual(ContentPersistencePayloadNormalizer::URL_KEY_MAX_LENGTH)
        ->and($payload['language'])->toBe('en')
        ->and($payload['translation_source_locale'])->toBe('nl');
});

it('falls back to untitled and api for empty generated payload fields', function () {
    $payload = ContentPersistencePayloadNormalizer::normalize([
        'source' => '',
        'title' => '',
        'external_key' => '',
        'language' => '',
        'translation_source_locale' => '',
    ]);

    expect($payload['source'])->toBe(ContentSource::API->value)
        ->and($payload['title'])->toBe('Untitled')
        ->and($payload['external_key'])->toBeNull()
        ->and($payload['language'])->toBe('en')
        ->and($payload['translation_source_locale'])->toBeNull();
});

it('normalizes dutch generated content titles and seo fields to sentence case', function () {
    $payload = ContentPersistencePayloadNormalizer::normalize([
        'language' => 'nl',
        'title' => 'Agentic Marketing: De Nieuwe AI-Gestuurde Aanpak voor het Plannen, Uitvoeren en Optimaliseren van Campagnes',
        'seo_title' => 'Agentic Marketing voor B2B-Teams: De Nieuwe Aanpak',
        'seo_h1' => 'De Nieuwe AI-Gestuurde Aanpak voor Content',
        'seo_meta_description' => 'Ontdek Hoe Argusly AI en B2B-Workflows Koppelt voor Betere Content.',
    ]);

    expect($payload['title'])->toBe('Agentic marketing: de nieuwe AI-gestuurde aanpak voor het plannen, uitvoeren en optimaliseren van campagnes')
        ->and($payload['seo_title'])->toBe('Agentic marketing voor B2B-teams: de nieuwe aanpak')
        ->and($payload['seo_h1'])->toBe('De nieuwe AI-gestuurde aanpak voor content')
        ->and($payload['seo_meta_description'])->toBe('Ontdek hoe Argusly AI en B2B-workflows koppelt voor betere content.');
});

it('keeps english generated content title casing unchanged', function () {
    $payload = ContentPersistencePayloadNormalizer::normalize([
        'language' => 'en',
        'title' => 'Agentic Marketing: The New AI-Driven Approach to Planning Campaigns',
    ]);

    expect($payload['title'])->toBe('Agentic Marketing: The New AI-Driven Approach to Planning Campaigns');
});

it('splits generated brief audience labels from long audience details', function () {
    $longAudience = 'Revenue operations leaders ' . str_repeat('who need detailed automation rollout context ', 20);

    $payload = ContentPersistencePayloadNormalizer::normalizeBrief([
        'source' => 'content_automation.run.with.extra.diagnostics',
        'title' => str_repeat('Brief title ', 40),
        'language' => 'en_US',
        'intent' => str_repeat('commercial investigation ', 20),
        'audience' => $longAudience,
        'search_intent' => str_repeat('informational ', 10),
        'content_type' => 'blog_post_with_unexpected_generated_suffix',
        'target_audience' => "  {$longAudience}\n\nAdditional context.  ",
    ]);

    $normalizedLongAudience = trim($longAudience);

    expect(mb_strlen((string) $payload['source']))->toBeLessThanOrEqual(ContentPersistencePayloadNormalizer::BRIEF_SOURCE_MAX_LENGTH)
        ->and(mb_strlen((string) $payload['title']))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and($payload['language'])->toBe('en')
        ->and(mb_strlen((string) $payload['intent']))->toBeLessThanOrEqual(ContentPersistencePayloadNormalizer::BRIEF_SHORT_TEXT_MAX_LENGTH)
        ->and(mb_strlen((string) $payload['audience']))->toBe(ContentPersistencePayloadNormalizer::BRIEF_AUDIENCE_MAX_LENGTH)
        ->and($payload['audience_details'])->toBe($normalizedLongAudience)
        ->and(mb_strlen((string) $payload['search_intent']))->toBeLessThanOrEqual(ContentPersistencePayloadNormalizer::BRIEF_SEARCH_INTENT_MAX_LENGTH)
        ->and(mb_strlen((string) $payload['content_type']))->toBeLessThanOrEqual(ContentPersistencePayloadNormalizer::BRIEF_CONTENT_TYPE_MAX_LENGTH)
        ->and($payload['target_audience'])->toContain('Additional context.');
});
