<?php

use App\Enums\FaqFunnelStage;
use App\Enums\FaqSearchIntent;
use App\Enums\FaqStatus;
use App\Enums\FaqType;
use App\Models\FaqQuestion;
use App\Services\Faq\FaqSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates valid FAQPage schema from FAQ questions', function (): void {
    $faq = FaqQuestion::query()->create([
        'question' => 'How does Argusly improve AI Visibility?',
        'answer' => 'Argusly improves AI Visibility by turning missing buyer questions into answer-ready FAQ content with clear entities, internal links and schema.',
        'language' => 'en',
        'faq_type' => FaqType::SOLUTION,
        'search_intent' => FaqSearchIntent::INFORMATIONAL,
        'funnel_stage' => FaqFunnelStage::CONSIDERATION,
        'status' => FaqStatus::PUBLISHED,
    ]);

    $schema = app(FaqSchemaService::class)->forQuestions(collect([$faq]));

    expect($schema)
        ->toBeArray()
        ->and($schema['@type'])->toBe('FAQPage')
        ->and($schema['mainEntity'][0]['name'])->toBe('How does Argusly improve AI Visibility?')
        ->and(app(FaqSchemaService::class)->validate($schema))->toBe([]);
});

it('reports validation errors for invalid FAQPage schema', function (): void {
    $errors = app(FaqSchemaService::class)->validate([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => [
            ['@type' => 'Question', 'name' => 'Missing answer'],
        ],
    ]);

    expect($errors)->not->toBeEmpty();
});
