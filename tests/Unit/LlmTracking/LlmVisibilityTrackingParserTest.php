<?php

use App\Services\LlmTracking\LlmVisibilityTrackingService;

it('parses brand url and competitor mentions deterministically', function () {
    $service = app(LlmVisibilityTrackingService::class);

    $result = $service->parseDeterministic(
        responseText: 'PublishLayer appears in rankings. See https://publishlayer.com/features and compare with AcmeSEO.',
        brandTerms: ['PublishLayer', 'PL'],
        competitorTerms: ['AcmeSEO', 'OtherBrand'],
        targetUrls: ['https://publishlayer.com/features', 'https://publishlayer.com/pricing'],
    );

    expect($result['brand_mentioned'])->toBeTrue();
    expect($result['urls_cited'])->toBeTrue();
    expect($result['competitors_mentioned'])->toBeTrue();
    expect($result['matched_brand_terms'])->toContain('PublishLayer');
    expect($result['matched_competitor_terms'])->toContain('AcmeSEO');
    expect($result['matched_target_urls'])->toContain('https://publishlayer.com/features');
});
