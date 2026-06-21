<?php

use App\Enums\FaqFunnelStage;
use App\Enums\FaqSearchIntent;
use App\Enums\FaqStatus;
use App\Enums\FaqType;
use App\Models\FaqQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

function publishedFaqForPage(array $overrides = []): FaqQuestion
{
    $faq = FaqQuestion::query()->create(array_merge([
        'question' => 'How does Argusly improve AI Visibility?',
        'answer' => 'Argusly improves AI Visibility by turning missing buyer questions into answer-ready FAQ content with clear entities, internal links and schema.',
        'language' => 'en',
        'faq_type' => FaqType::SOLUTION,
        'search_intent' => FaqSearchIntent::COMMERCIAL,
        'funnel_stage' => FaqFunnelStage::CONSIDERATION,
        'priority' => 70,
        'status' => FaqStatus::PUBLISHED,
    ], $overrides));

    $faq->assignments()->create([
        'page_type' => 'solution',
        'page_slug' => 'ai-visibility',
        'locale' => $faq->language,
        'weight' => 90,
    ]);

    return $faq;
}

it('renders published FAQ content and FAQPage JSON LD for a public page', function (): void {
    publishedFaqForPage();

    $html = Blade::render('<x-public.faq-section page-type="solution" page-slug="ai-visibility" locale="en" />');

    expect($html)
        ->toContain('How does Argusly improve AI Visibility?')
        ->toContain('application/ld+json')
        ->toContain('"@type":"FAQPage"');
});

it('does not output FAQ schema when a page has no active FAQ', function (): void {
    $html = Blade::render('<x-public.faq-section page-type="solution" page-slug="missing" locale="en" />');

    expect(trim($html))->toBe('');
});

it('renders localized headings for NL and EN FAQ sections', function (): void {
    publishedFaqForPage([
        'question' => 'Hoe helpt Argusly bij AI Visibility?',
        'answer' => 'Argusly helpt door ontbrekende buyer questions om te zetten naar antwoordklare FAQ content.',
        'language' => 'nl',
    ]);
    publishedFaqForPage();

    $nl = Blade::render('<x-public.faq-section page-type="solution" page-slug="ai-visibility" locale="nl" />');
    $en = Blade::render('<x-public.faq-section page-type="solution" page-slug="ai-visibility" locale="en" />');

    expect($nl)->toContain('Veelgestelde vragen')
        ->and($en)->toContain('Frequently asked questions');
});

it('outputs FAQPage JSON LD for solution, market, pricing and contact assignments', function (): void {
    foreach ([
        ['solution', 'ai-visibility'],
        ['market', 'saas'],
        ['pricing', 'pricing'],
        ['contact', 'company.contact'],
    ] as [$pageType, $pageSlug]) {
        $faq = FaqQuestion::query()->create([
            'question' => "What should buyers know about {$pageType}?",
            'answer' => 'Argusly answers the buyer question directly with AI Visibility, semantic SEO and conversion context.',
            'language' => 'en',
            'faq_type' => FaqType::RESOURCE,
            'search_intent' => FaqSearchIntent::COMMERCIAL,
            'funnel_stage' => FaqFunnelStage::CONSIDERATION,
            'priority' => 70,
            'status' => FaqStatus::PUBLISHED,
        ]);

        $faq->assignments()->create([
            'page_type' => $pageType,
            'page_slug' => $pageSlug,
            'locale' => 'en',
            'weight' => 80,
        ]);

        $html = Blade::render('<x-public.faq-section :page-type="$pageType" :page-slug="$pageSlug" locale="en" />', [
            'pageType' => $pageType,
            'pageSlug' => $pageSlug,
        ]);

        expect($html)->toContain('"@type":"FAQPage"');
    }
});
