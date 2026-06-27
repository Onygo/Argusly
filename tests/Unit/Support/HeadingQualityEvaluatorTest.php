<?php

use App\Support\HeadingQualityEvaluator;

it('rejects generic ai structural headings across supported languages', function (string $heading) {
    $evaluation = app(HeadingQualityEvaluator::class)->evaluateHeadings([
        ['level' => 2, 'text' => $heading],
    ], [
        'primary_keyword' => 'autonomous marketing',
        'secondary_keywords' => ['marketing strategy'],
        'intent_keys' => ['guide'],
    ]);

    expect($evaluation['passed'])->toBeFalse()
        ->and($evaluation['score'])->toBeLessThan(HeadingQualityEvaluator::MIN_SCORE)
        ->and($evaluation['issues'][0])->toContain('generic structural label');
})->with([
    'Hoofdsectie',
    'Main Section',
    'Conclusie',
    'Conclusion',
    'Samenvatting',
    'Summary',
    'Inleiding',
    'Introduction',
    'Sectie 1',
    'Section 1',
    'Belangrijkste punten',
    'Key Takeaways',
    'Eindgedachten',
    'Final Thoughts',
    'Closing Remarks',
    'Hoofdsectie: De 7 marketinglessen van Google',
    'Main Section: The future of AI visibility',
]);

it('passes descriptive editorial headings with topical relevance', function () {
    $evaluation = app(HeadingQualityEvaluator::class)->evaluateHeadings([
        ['level' => 2, 'text' => 'What autonomous marketing really means for B2B teams'],
        ['level' => 2, 'text' => 'Why autonomous marketing improves marketing performance'],
        ['level' => 2, 'text' => 'Turning autonomous marketing insights into action'],
    ], [
        'primary_keyword' => 'autonomous marketing',
        'secondary_keywords' => ['marketing performance'],
        'intent_keys' => ['guide', 'strategic'],
    ]);

    expect($evaluation['passed'])->toBeTrue()
        ->and($evaluation['score'])->toBeGreaterThanOrEqual(HeadingQualityEvaluator::MIN_SCORE)
        ->and($evaluation['issues'])->toBe([]);
});
