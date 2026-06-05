<?php

use App\Models\Brief;
use App\Models\Draft;
use App\Services\Drafts\Intelligence\DraftSignalExtractor;
use Illuminate\Support\Collection;

it('extracts deterministic seo readability cta and heading signals from a parsed snapshot', function () {
    $draft = new Draft([
        'title' => 'Telecom automation in 30 days',
        'seo_title' => 'Telecom automation in 30 days',
        'seo_meta_description' => 'A practical 30-day telecom automation guide.',
        'content_html' => '<h1>Telecom automation in 30 days</h1><p>Telecom automation starts with one workflow pilot.</p><h2>Choose the first workflow</h2><p>Plan a short workshop with your operations team and use this checklist to launch your first pilot.</p>',
    ]);
    $draft->setRelation('brief', new Brief([
        'primary_keyword' => 'telecom automation',
        'secondary_keywords' => ['workflow pilot', '30-day plan'],
        'target_audience' => 'operations teams',
        'call_to_action' => 'Plan a pilot workshop',
        'funnel_stage' => 'consideration',
    ]));
    $draft->setRelation('articleEntities', new Collection());

    $snapshot = [
        'title' => 'Telecom automation in 30 days',
        'seo_title' => 'Telecom automation in 30 days',
        'seo_meta_description' => 'A practical 30-day telecom automation guide.',
        'seo_h1' => 'Telecom automation in 30 days',
        'content_html' => (string) $draft->content_html,
        'plain_text' => 'Telecom automation starts with one workflow pilot. Plan a short workshop with your operations team and use this checklist to launch your first pilot.',
        'intro' => 'Telecom automation starts with one workflow pilot.',
        'conclusion' => 'Plan a short workshop with your operations team and use this checklist to launch your first pilot.',
        'headings' => [
            ['level' => 1, 'text' => 'Telecom automation in 30 days'],
            ['level' => 2, 'text' => 'Choose the first workflow'],
        ],
        'sections' => [],
        'paragraphs' => [
            'Telecom automation is the practice of replacing repetitive telecom workflows with one clearly owned pilot.',
            'Plan a short workshop with your operations team and use this checklist to launch your first pilot.',
        ],
        'cta_candidate_blocks' => ['Plan a short workshop with your operations team and use this checklist to launch your first pilot.'],
        'summary_section_count' => 1,
        'faq_section_count' => 0,
        'comparison_section_count' => 0,
        'step_section_count' => 1,
        'definition_passages' => ['Telecom automation is the practice of replacing repetitive telecom workflows with one clearly owned pilot.'],
        'extractable_passages' => [
            'Telecom automation is the practice of replacing repetitive telecom workflows with one clearly owned pilot.',
            'Plan a short workshop with your operations team and use this checklist to launch your first pilot.',
        ],
        'secondary_keywords' => ['workflow pilot', '30-day plan'],
        'primary_keyword' => 'telecom automation',
        'target_audience' => 'operations teams',
        'call_to_action' => 'Plan a pilot workshop',
        'funnel_stage' => 'consideration',
        'brand_voice' => [
            'preferred_terminology' => ['workflow pilot'],
            'disallowed_terminology' => ['revolutionary'],
        ],
        'company_profile' => [
            'value_propositions' => ['reduce repetitive work'],
            'proof_points' => ['launch your first pilot'],
        ],
        'expected_entities' => ['telecom automation', 'workflow pilot'],
        'detected_entities' => [],
        'list_count' => 0,
        'sentence_count' => 2,
        'word_count' => 24,
    ];

    $signals = app(DraftSignalExtractor::class)->extract($draft, $snapshot);

    expect($signals['seo']['title_has_primary_keyword'])->toBeTrue()
        ->and($signals['seo']['intro_has_primary_keyword'])->toBeTrue()
        ->and($signals['readability']['average_sentence_words'])->toBeGreaterThan(5)
        ->and($signals['cta']['cta_present'])->toBeTrue()
        ->and($signals['cta']['cta_near_end'])->toBeTrue()
        ->and($signals['headings']['h1_present'])->toBeTrue()
        ->and($signals['headings']['hierarchy_consistent'])->toBeTrue()
        ->and($signals['llm_visibility']['explicit_answer_presence'])->toBeTrue()
        ->and($signals['llm_visibility']['extractable_summary_block_present'])->toBeTrue()
        ->and($signals['llm_visibility']['step_based_section_present'])->toBeTrue()
        ->and($signals['brand_voice_fit']['preferred_terminology_coverage'])->toBeGreaterThan(0)
        ->and($signals['conversion_fit']['decision_support_score'])->toBeGreaterThan(0)
        ->and($signals['trust_evidence']['recommendation_clarity_score'])->toBeGreaterThan(0);
});
