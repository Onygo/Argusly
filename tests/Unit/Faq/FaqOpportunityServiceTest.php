<?php

use App\Data\Faq\FaqPageInput;
use App\Enums\FaqFunnelStage;
use App\Enums\FaqSearchIntent;
use App\Enums\FaqStatus;
use App\Enums\FaqType;
use App\Models\FaqQuestion;
use App\Services\Faq\FaqOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function faqInput(array $overrides = []): FaqPageInput
{
    return FaqPageInput::fromArray(array_merge([
        'page_title' => 'AI Visibility for B2B teams',
        'meta_title' => 'AI Visibility | Argusly',
        'meta_description' => 'Measure and improve how AI systems understand your brand.',
        'h1' => 'See how AI systems understand your brand',
        'h2s' => ['Measure AI answer presence', 'Improve answer-ready content'],
        'content' => 'Argusly helps B2B teams improve content operations and opportunity intelligence.',
        'internal_links' => ['AI Visibility', 'Contact'],
        'sector' => 'IT Services & SaaS',
        'solution_type' => 'AI Visibility',
        'page_type' => 'solution',
        'page_slug' => 'ai-visibility',
        'locale' => 'en',
    ], $overrides));
}

it('detects missing FAQ opportunities and generates prioritized FAQ candidates', function (): void {
    $result = app(FaqOpportunityService::class)->analyze(faqInput());

    expect($result['scores']['faq_opportunity_score']['score'])->toBeGreaterThan(0)
        ->and($result['scores']['ai_visibility_impact_score']['score'])->toBeGreaterThan(0)
        ->and($result['recommended_faqs'])->not->toBeEmpty()
        ->and($result['faq_schema']['@type'])->toBe('FAQPage');
});

it('publishes generated FAQ candidates and creates page assignments', function (): void {
    $input = faqInput();
    $service = app(FaqOpportunityService::class);
    $result = $service->analyze($input);

    $created = $service->publishGeneratedFaqs($input, array_slice($result['recommended_faqs'], 0, 2));

    expect($created)->toHaveCount(2)
        ->and(FaqQuestion::query()->count())->toBe(2)
        ->and($created->first()->assignments()->count())->toBe(1)
        ->and($created->first()->status)->toBe(FaqStatus::PUBLISHED);
});

it('does not publish duplicate questions', function (): void {
    FaqQuestion::query()->create([
        'question' => 'Hoe helpt dit bij AI Visibility?',
        'answer' => 'Argusly answers this already.',
        'language' => 'nl',
        'faq_type' => FaqType::SOLUTION,
        'search_intent' => FaqSearchIntent::INFORMATIONAL,
        'funnel_stage' => FaqFunnelStage::CONSIDERATION,
        'status' => FaqStatus::PUBLISHED,
    ]);

    $input = faqInput(['locale' => 'nl']);
    $created = app(FaqOpportunityService::class)->publishGeneratedFaqs($input, [[
        'question' => 'Hoe helpt dit bij AI Visibility?',
        'answer' => 'Duplicated answer.',
        'faq_type' => FaqType::SOLUTION->value,
        'search_intent' => FaqSearchIntent::INFORMATIONAL->value,
        'funnel_stage' => FaqFunnelStage::CONSIDERATION->value,
    ]]);

    expect($created)->toHaveCount(0)
        ->and(FaqQuestion::query()->count())->toBe(1);
});
