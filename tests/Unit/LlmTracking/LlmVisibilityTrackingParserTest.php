<?php

use App\Services\LlmTracking\LlmVisibilityTrackingService;

it('parses brand url and competitor mentions deterministically', function () {
    $service = app(LlmVisibilityTrackingService::class);

    $result = $service->parseDeterministic(
        responseText: 'Argusly appears in rankings. See https://argusly.com/features and compare with AcmeSEO.',
        brandTerms: ['Argusly', 'PL'],
        competitorTerms: ['AcmeSEO', 'OtherBrand'],
        targetUrls: ['https://argusly.com/features', 'https://argusly.com/pricing'],
    );

    expect($result['brand_mentioned'])->toBeTrue();
    expect($result['urls_cited'])->toBeTrue();
    expect($result['competitors_mentioned'])->toBeTrue();
    expect($result['matched_brand_terms'])->toContain('Argusly');
    expect($result['matched_competitor_terms'])->toContain('AcmeSEO');
    expect($result['matched_target_urls'])->toContain('https://argusly.com/features');
});
