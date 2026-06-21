<?php

use App\Enums\FaqWorkflowStatus;
use App\Jobs\Faq\AnalyzeFaqPageJob;
use App\Jobs\Faq\RecalculateFaqCoverageScoresJob;
use App\Models\FaqOpportunityAudit;
use App\Services\Faq\FaqOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs the background analysis job and stores review-ready FAQ opportunities', function (): void {
    $payload = [
        'page_title' => 'AI Visibility',
        'meta_title' => 'AI Visibility | Argusly',
        'meta_description' => 'Measure AI answer visibility.',
        'h1' => 'AI Visibility',
        'h2s' => ['Signals', 'Governance'],
        'content' => 'Argusly helps B2B teams improve opportunity intelligence.',
        'internal_links' => ['Contact'],
        'sector' => 'IT Services',
        'solution_type' => 'AI Visibility',
        'page_type' => 'solution',
        'page_slug' => 'ai-visibility',
        'locale' => 'en',
    ];

    (new AnalyzeFaqPageJob($payload))->handle(app(FaqOpportunityService::class));

    $audit = FaqOpportunityAudit::query()->first();

    expect($audit)->not->toBeNull()
        ->and($audit->status)->toBe(FaqWorkflowStatus::REVIEW_REQUIRED)
        ->and($audit->generated_faqs)->not->toBeEmpty();
});

it('recalculates coverage scores for an audit', function (): void {
    $audit = FaqOpportunityAudit::query()->create([
        'page_type' => 'pricing',
        'page_slug' => 'pricing',
        'locale' => 'en',
        'status' => FaqWorkflowStatus::PENDING,
        'missing_questions' => ['roi_questions' => ['How do we measure ROI?']],
    ]);

    (new RecalculateFaqCoverageScoresJob($audit->id))->handle(app(\App\Repositories\FaqQuestionRepository::class));

    expect($audit->fresh()->faq_coverage_score)->toBe('31.00');
});
