<?php

use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function fakeDraftAnalysisResponse(int $ctaScore, string $ctaExplanation): LlmResponse
{
    return new LlmResponse(
        text: '{}',
        json: [
            'summary' => [
                'headline' => 'Draft analysis',
                'overall_explanation' => 'Overall draft analysis.',
            ],
            'sections' => [
                'seo' => ['score' => 62, 'explanation' => 'SEO is acceptable.', 'improvements' => ['Tighten the title.']],
                'readability' => ['score' => 64, 'explanation' => 'Readability is acceptable.', 'improvements' => ['Break one long paragraph.']],
                'cta' => ['score' => $ctaScore, 'explanation' => $ctaExplanation, 'improvements' => ['Strengthen the CTA.']],
                'structure' => ['score' => 63, 'explanation' => 'Structure is clear.', 'improvements' => ['Refine one heading.']],
                'entities' => ['score' => 61, 'explanation' => 'Entity coverage is acceptable.', 'improvements' => ['Add one supporting entity.']],
            ],
            'keyword_coverage' => [
                'score' => 60,
                'covered_terms' => ['telecom processen automatiseren'],
                'missing_terms' => [],
                'explanation' => 'Keyword coverage is acceptable.',
            ],
            'entity_coverage' => [
                'score' => 60,
                'detected_entities' => ['CTO'],
                'missing_entities' => [],
                'explanation' => 'Entity coverage is acceptable.',
            ],
            'internal_link_summary' => 'No link opportunities.',
            'internal_link_opportunities' => [],
            'top_improvements' => ['Strengthen CTA', 'Tighten title', 'Improve structure'],
        ],
        usage: new LlmUsage(200, 120, 320),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-draft-cta-analysis',
    );
}
