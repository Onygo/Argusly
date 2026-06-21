<?php

use App\Enums\FaqFunnelStage;
use App\Enums\FaqSearchIntent;
use App\Enums\FaqStatus;
use App\Enums\FaqType;
use App\Models\FaqQuestion;
use App\Services\Faq\FaqDuplicateDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detects exact duplicate FAQ questions and recommends reuse', function (): void {
    foreach ([1, 2] as $index) {
        FaqQuestion::query()->create([
            'question' => 'How does Argusly improve AI Visibility?',
            'answer' => 'Argusly creates answer-ready FAQ content.',
            'language' => 'en',
            'faq_type' => FaqType::SOLUTION,
            'search_intent' => FaqSearchIntent::INFORMATIONAL,
            'funnel_stage' => FaqFunnelStage::AWARENESS,
            'status' => FaqStatus::PUBLISHED,
        ]);
    }

    $risks = app(FaqDuplicateDetectionService::class)->risks('en');

    expect($risks->pluck('risk_type'))->toContain('exact_duplicate_question')
        ->and($risks->firstWhere('risk_type', 'exact_duplicate_question')['advice'])->toBe('hergebruiken');
});
