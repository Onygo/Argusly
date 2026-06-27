<?php

use App\Services\Editorial\EditorialPatternLibrary;

it('selects different editorial patterns for different brief contexts', function (): void {
    $library = app(EditorialPatternLibrary::class);

    $comparison = $library->select([
        'title' => 'HubSpot vs Marketo comparison for B2B teams',
        'topic' => 'HubSpot vs Marketo comparison',
        'search_intent' => 'commercial',
        'funnel_stage' => 'decision',
        'research_insights' => [],
        'previous_related_articles' => [],
        'key_points' => [],
    ]);

    $forecast = $library->select([
        'title' => 'AI search predictions for 2027',
        'topic' => 'Future AI search predictions and evidence',
        'search_intent' => 'informational',
        'funnel_stage' => 'awareness',
        'research_insights' => ['Recent tracking runs show changing citation behavior.'],
        'previous_related_articles' => [],
        'key_points' => [],
    ]);

    expect(data_get($comparison, 'primary.name'))->toBe('Comparison')
        ->and(data_get($forecast, 'primary.name'))->toBe('Prediction to Evidence')
        ->and(data_get($comparison, 'primary.name'))->not->toBe(data_get($forecast, 'primary.name'));
});

it('does not expose generic intro body conclusion patterns', function (): void {
    $library = app(EditorialPatternLibrary::class);

    $allPatternText = collect($library->patterns())
        ->flatMap(fn (array $pattern): array => [
            (string) $pattern['name'],
            (string) $pattern['article_movement'],
            (string) $pattern['heading_guidance'],
            (string) $pattern['rhythm_guidance'],
        ])
        ->implode(' ');

    expect(preg_match('/\b(intro|body|conclusion)\b/i', $allPatternText))->toBe(0)
        ->and($allPatternText)->not->toContain('Opening, Main Section');
});
