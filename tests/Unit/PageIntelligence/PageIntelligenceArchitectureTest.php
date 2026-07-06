<?php

it('keeps page intelligence fetch and extraction services centralized', function (): void {
    $services = collect(glob(app_path('Services/PageIntelligence/**/*.php')) ?: [])
        ->merge(glob(app_path('Services/PageIntelligence/*.php')) ?: [])
        ->map(fn (string $path): string => str_replace(app_path().'/', '', $path))
        ->filter(fn (string $path): bool => preg_match('/(Fetch|Fetcher|Crawler|Extract|Extractor)/', basename($path)) === 1)
        ->reject(fn (string $path): bool => in_array($path, [
            'Services/PageIntelligence/PageCrawlerSafetyService.php',
            'Services/PageIntelligence/PageFetcher.php',
            'Services/PageIntelligence/PageFetchResult.php',
            'Services/PageIntelligence/PageContentExtractor.php',
            'Services/PageIntelligence/PageExtractionResult.php',
        ], true))
        ->values();

    expect($services->all())->toBe([]);
});

it('documents the page intelligence ingestion boundary', function (): void {
    expect(file_exists(base_path('docs/architecture/page-intelligence-hardening.md')))->toBeTrue();
});
