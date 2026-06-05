<?php

use App\Services\Drafts\Intelligence\DraftMetricScorer;

it('keeps CTA score explanations aligned with the final score band', function () {
    $scorer = app(DraftMetricScorer::class);

    $snapshot = [];
    $signals = [
        'seo' => [
            'title_has_primary_keyword' => true,
            'intro_has_primary_keyword' => true,
            'headings_with_primary_keyword' => 1,
            'related_terms_present' => 2,
            'related_term_total' => 2,
            'meta_title_present' => true,
            'meta_description_present' => true,
            'keyword_stuffing_detected' => false,
            'internal_link_present' => true,
        ],
        'readability' => [
            'average_sentence_words' => 18,
            'average_paragraph_words' => 46,
            'heading_count' => 2,
            'list_present' => false,
            'scanability' => true,
            'dense_block_count' => 0,
            'transition_ratio' => 0.4,
        ],
        'cta' => [
            'score' => 72,
            'band_label' => '61-80: clear, relevant, actionable CTA',
            'explanation' => 'The CTA is clear, relevant, and actionable for this consideration-stage article without forcing a hard-sales ask.',
            'improvements' => ['Add a touch more specificity to the CTA outcome.'],
        ],
        'headings' => [
            'h1_present' => true,
            'h1_count' => 1,
            'heading_count' => 2,
            'hierarchy_consistent' => true,
            'hierarchy_issue_count' => 0,
            'duplicate_heading_count' => 0,
            'generic_heading_count' => 0,
            'descriptive_heading_ratio' => 1.0,
            'section_coverage' => 1.8,
        ],
        'entities' => [
            'detected' => [],
            'missing' => [],
            'coverage_ratio' => 1.0,
        ],
    ];

    $result = $scorer->score($snapshot, $signals, [
        'cta' => ['score' => 68],
    ]);

    expect(data_get($result, 'sections.cta.score'))->toBeGreaterThanOrEqual(61)
        ->and((string) data_get($result, 'sections.cta.band_label'))->toBe('61-80: clear, relevant, actionable CTA')
        ->and((string) data_get($result, 'sections.cta.explanation'))->toContain('clear, relevant, and actionable');
});
