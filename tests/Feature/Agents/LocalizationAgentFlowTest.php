<?php

require_once __DIR__ . '/../../Support/LocalizationAgentTestHelpers.php';

use App\Agents\Localization\LocalizationAgent;
use App\Models\AgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders content localization recommendations with translation actions in the ui', function () {
    [$owner, $workspace, $site] = makeLocalizationAgentContext('feature-content-localization', true);

    $source = makeLocalizedContent($workspace, $site, $owner, 'Source article', 'en');
    attachLocalizedVersion($source, '<p>Fresh English source body.</p>', now());

    $translation = makeLocalizedContent($workspace, $site, $owner, 'Nederlandse versie', 'nl', [
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'seo_meta_description' => null,
        'publish_url_key' => null,
        'translation_generated_at' => now()->subDays(10),
        'translation_source_updated_at' => now()->subDays(10),
    ]);
    attachLocalizedVersion($translation, '<p>Oudere Nederlandse body.</p>', now()->subDays(10));

    $this->actingAs($owner)
        ->post(route('app.content.localization.run', $source), ['tab' => 'overview'])
        ->assertRedirect();

    $run = AgentRun::query()
        ->where('agent_key', LocalizationAgent::KEY)
        ->where('trigger_source', 'app.content.localization')
        ->latest('created_at')
        ->firstOrFail();

    expect($run->agent_key)->toBe(LocalizationAgent::KEY)
        ->and($run->trigger_source)->toBe('app.content.localization');

    $this->actingAs($owner)
        ->get(route('app.content.show', [
            'content' => $source,
            'tab' => 'overview',
            'insight' => 'localization',
            'localization_run' => $run->id,
        ]))
        ->assertOk()
        ->assertSee('Content Health')
        ->assertSee('AI findings')
        ->assertSee('Refresh NL translation')
        ->assertSee('Create DE translation');
});

it('renders draft localization recommendations for locale mismatches', function () {
    [$owner, $workspace, $site] = makeLocalizationAgentContext('feature-draft-localization', true);

    $content = makeLocalizedContent($workspace, $site, $owner, 'English labeled article', 'en');
    $draft = makeLocalizedDraft(
        $content,
        $site,
        'Nederlandse handleiding',
        'en',
        '<p>Dit is een Nederlandse gids en deze uitleg is voor teams in Nederland.</p>',
        [
            'seo_meta_description' => '',
        ]
    );

    $this->actingAs($owner)
        ->post(route('app.drafts.localization.run', $draft), ['tab' => 'draft'])
        ->assertRedirect();

    $run = AgentRun::query()
        ->where('agent_key', LocalizationAgent::KEY)
        ->where('trigger_source', 'app.drafts.localization')
        ->latest('created_at')
        ->firstOrFail();

    $this->actingAs($owner)
        ->get(route('app.drafts.show', [
            'draft' => $draft,
            'tab' => 'intelligence',
            'localization_run' => $run->id,
        ]))
        ->assertOk()
        ->assertSee('Localization')
        ->assertSee('Warning');
});
