<?php

use App\Models\AgenticMarketingOpportunity;
use App\Models\ContentOpportunity;
use App\Models\FaqOpportunityAudit;
use App\Models\LinkOpportunity;
use App\Models\Opportunity;
use App\Services\Mos\Contracts\MosOpportunityProvider;
use App\Services\Mos\MosProviderRegistry;
use Illuminate\Support\Facades\DB;

it('normalizes content opportunities into canonical candidate payloads', function () {
    $provider = app(MosProviderRegistry::class)->get('legacy-content-opportunities');

    expect($provider)->toBeInstanceOf(MosOpportunityProvider::class);

    $source = (new ContentOpportunity)->forceFill([
        'id' => 'content-opportunity-1',
        'organization_id' => 10,
        'workspace_id' => 'workspace-1',
        'client_site_id' => 'site-1',
        'type' => 'content_gap',
        'status' => ContentOpportunity::STATUS_OPEN,
        'title' => 'Build comparison content',
        'why_this_matters' => 'Competitors own this decision intent.',
        'priority_score' => 82,
        'confidence_score' => 74,
        'expected_impact' => 88,
        'business_value_score' => 79,
        'source_signals' => [['source' => 'competitor-gap']],
        'suggested_cta' => 'Create a comparison brief',
        'dedupe_hash' => 'content-dedupe-1',
    ]);

    $candidate = $provider->toCanonicalOpportunity($source);

    expect($candidate->toArray())->toMatchArray([
        'title' => 'Build comparison content',
        'description' => 'Competitors own this decision intent.',
        'type' => 'content_gap',
        'source' => 'legacy-content-opportunities',
        'source_model' => ContentOpportunity::class,
        'source_id' => 'content-opportunity-1',
        'priority' => 82.0,
        'confidence' => 74.0,
        'impact' => 88.0,
        'business_value' => 79.0,
        'lifecycle_status' => ContentOpportunity::STATUS_OPEN,
        'dedupe_key' => 'content-dedupe-1',
        'missing_fields' => [],
        'unsupported_reasons' => [],
        'can_persist_canonically' => true,
    ])
        ->and($candidate->context)->toMatchArray([
            'organization_id' => 10,
            'workspace_id' => 'workspace-1',
            'client_site_id' => 'site-1',
        ])
        ->and($candidate->evidence)->toBe([['source' => 'competitor-gap']]);
});

it('reports missing fields and unsupported conversions explicitly', function () {
    $faqProvider = app(MosProviderRegistry::class)->get('legacy-faq-opportunity-audits');
    $linkProvider = app(MosProviderRegistry::class)->get('legacy-link-opportunities');

    $faqCandidate = $faqProvider->toCanonicalOpportunity(new FaqOpportunityAudit);
    $linkCandidate = $linkProvider->toCanonicalOpportunity((new LinkOpportunity)->forceFill([
        'id' => 'link-opportunity-1',
        'workspace_id' => 'workspace-1',
        'source_content_id' => 'source-content-1',
        'target_content_id' => 'target-content-1',
        'status' => LinkOpportunity::STATUS_SUGGESTED,
        'relevance_score' => 67,
    ]));

    expect($faqCandidate->missingFields)->toContain('page_title', 'workspace_or_organization_context')
        ->and($faqCandidate->canPersistCanonically())->toBeFalse()
        ->and($linkProvider->canEmitCanonicalOpportunities())->toBeFalse()
        ->and($linkCandidate->unsupportedReasons)->not->toBeEmpty()
        ->and($linkCandidate->canPersistCanonically())->toBeFalse();
});

it('normalizes agentic marketing payload details without persisting canonical opportunities', function () {
    $provider = app(MosProviderRegistry::class)->get('legacy-agentic-marketing-opportunities');
    $source = (new AgenticMarketingOpportunity)->forceFill([
        'id' => 'agentic-opportunity-1',
        'objective_id' => 'objective-1',
        'content_id' => 'content-1',
        'title' => 'Refresh stale answer block',
        'type' => 'answer_coverage',
        'status' => 'open',
        'priority_score' => 91,
        'dedupe_hash' => 'agentic-dedupe-1',
        'payload' => [
            'summary' => 'Answer coverage is weak.',
            'confidence_score' => 69,
            'impact_score' => 80,
            'recommended_actions' => ['Generate an answer-led section'],
            'evidence' => ['missing answer block'],
        ],
    ]);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $candidate = $provider->toCanonicalOpportunity($source);

    expect(DB::getQueryLog())->toBe([])
        ->and($candidate)->not->toBeInstanceOf(Opportunity::class)
        ->and($source->exists)->toBeFalse()
        ->and($candidate->toArray())->toMatchArray([
            'title' => 'Refresh stale answer block',
            'description' => 'Answer coverage is weak.',
            'type' => 'answer_coverage',
            'source' => 'legacy-agentic-marketing-opportunities',
            'source_id' => 'agentic-opportunity-1',
            'priority' => 91.0,
            'confidence' => 69.0,
            'impact' => 80.0,
            'dedupe_key' => 'agentic-dedupe-1',
            'can_persist_canonically' => true,
        ]);
});
