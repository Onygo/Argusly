<?php

use App\Models\SeoAuditIssue;
use App\Models\SeoAuditPage;
use App\Services\SeoAudit\SeoAuditScoreCalculator;
use Illuminate\Support\Collection;

it('calculates page score with configured deductions and clamps to zero', function () {
    $calculator = app(SeoAuditScoreCalculator::class);

    $score = $calculator->scoreFromIssueCodes([
        'title_missing',
        'title_missing',
        'meta_description_missing',
        'canonical_missing',
        'h1_missing',
        'broken_links_detected',
        'unknown_issue',
    ]);

    expect($score)->toBe(25);

    $zeroScore = $calculator->scoreFromIssueCodes([
        'title_missing',
        'meta_description_missing',
        'canonical_missing',
        'h1_missing',
        'broken_links_detected',
        'title_long',
        'title_missing',
        'meta_description_missing',
    ]);

    expect($zeroScore)->toBe(15);
});

it('calculates overall audit score as equal weighted page average', function () {
    $calculator = app(SeoAuditScoreCalculator::class);

    $pageA = new SeoAuditPage();
    $pageA->setAttribute('id', 101);

    $pageB = new SeoAuditPage();
    $pageB->setAttribute('id', 102);

    $issues = new Collection([
        (new SeoAuditIssue())->forceFill(['seo_audit_page_id' => 101, 'code' => 'title_missing']),
        (new SeoAuditIssue())->forceFill(['seo_audit_page_id' => 101, 'code' => 'meta_description_missing']),
        (new SeoAuditIssue())->forceFill(['seo_audit_page_id' => 102, 'code' => 'title_long']),
    ]);

    $score = $calculator->scoreForAudit(collect([$pageA, $pageB]), $issues);

    // pageA: 100 - 25 - 15 = 60
    // pageB: 100 - 10 = 90
    // avg: 75
    expect($score)->toBe(75.0);

    $level = $calculator->levelForScore($score);
    expect($level['label'])->toBe('Needs improvement');
});
