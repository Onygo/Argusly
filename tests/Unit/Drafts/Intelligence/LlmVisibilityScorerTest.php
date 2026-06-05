<?php

use App\Models\Brief;
use App\Models\Draft;
use App\Services\Drafts\Intelligence\DraftMetricScorer;
use App\Services\Drafts\Intelligence\DraftStructureParser;
use App\Services\Drafts\Intelligence\DraftSignalExtractor;
use Illuminate\Support\Collection;

it('scores answer-first structured content higher for llm visibility than vague prose', function () {
    $strongHtml = <<<'HTML'
<h1>What telecom automation means</h1>
<p>Telecom automation is the practice of replacing repetitive telecom workflows with rule-based or AI-assisted steps.</p>
<h2>Summary</h2>
<p>In short, start with one workflow, assign an owner, and measure one pilot outcome.</p>
<h2>Three steps</h2>
<ul>
  <li>Map one repetitive workflow</li>
  <li>Choose the owner</li>
  <li>Measure the pilot result</li>
</ul>
HTML;

    $weakHtml = <<<'HTML'
<h1>Automation ideas</h1>
<p>This is important for many teams and it can help in many situations.</p>
<p>There are things to consider and different ways to approach it depending on what works.</p>
HTML;

    $strongResult = llmVisibilityScoreForHtml($strongHtml);
    $weakResult = llmVisibilityScoreForHtml($weakHtml);

    expect(data_get($strongResult, 'sections.llm_visibility.score'))->toBeGreaterThan(data_get($weakResult, 'sections.llm_visibility.score'))
        ->and((string) data_get($strongResult, 'sections.llm_visibility.explanation'))->toContain('AI systems');
});

function llmVisibilityScoreForHtml(string $html): array
{
    $draft = new Draft([
        'title' => 'Telecom automation article',
        'seo_title' => 'Telecom automation article',
        'seo_meta_description' => 'Telecom automation article summary.',
        'seo_h1' => 'Telecom automation article',
        'content_html' => $html,
    ]);
    $draft->setRelation('brief', new Brief([
        'primary_keyword' => 'telecom automation',
        'secondary_keywords' => ['workflow pilot'],
        'target_audience' => 'operations teams',
        'call_to_action' => 'Plan a pilot workshop',
        'funnel_stage' => 'consideration',
    ]));
    $draft->setRelation('articleEntities', new Collection());

    $parsed = app(DraftStructureParser::class)->parse($html);
    $snapshot = array_merge($parsed, [
        'title' => (string) $draft->title,
        'seo_title' => (string) $draft->seo_title,
        'seo_meta_description' => (string) $draft->seo_meta_description,
        'seo_h1' => (string) $draft->seo_h1,
        'content_html' => $html,
        'primary_keyword' => 'telecom automation',
        'secondary_keywords' => ['workflow pilot'],
        'target_audience' => 'operations teams',
        'call_to_action' => 'Plan a pilot workshop',
        'funnel_stage' => 'consideration',
        'expected_entities' => ['telecom automation', 'workflow pilot'],
        'detected_entities' => [],
    ]);

    $signals = app(DraftSignalExtractor::class)->extract($draft, $snapshot);

    return app(DraftMetricScorer::class)->score($snapshot, $signals);
}
