<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\ClientSite;
use App\Models\CompetitorContentOpportunity;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\OpportunityIntelligence\CompetitorContentOpportunitySignalPromotionService;
use App\Services\OpportunityIntelligence\CompetitorOpportunitySignalValidationService;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function competitorPromotionContext(string $slug = 'competitor-promotion'): array
{
    $organization = Organization::query()->create([
        'name' => 'Competitor Promotion '.$slug,
        'slug' => $slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Argusly',
        'display_name' => 'Argusly',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Argusly Site',
        'site_url' => 'https://argusly.test',
        'base_url' => 'https://argusly.test',
        'allowed_domains' => ['argusly.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'CompetitorOS',
        'domain' => 'competitorios.test',
        'is_active' => true,
    ]);

    return [$organization, $workspace, $site, $competitor];
}

function competitorPromotionOpportunity(array $overrides = []): CompetitorContentOpportunity
{
    [, $workspace, $site, $competitor] = competitorPromotionContext('promotion-'.str()->random(8));

    return CompetitorContentOpportunity::factory()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'site_competitor_id' => $competitor->id,
        'title' => 'Create an answer-led AI visibility comparison',
        'topic' => 'AI visibility comparison',
        'priority_score' => 88,
        'confidence_score' => 76,
        'impact_score' => 91,
        'effort_score' => 42,
        'attackable_angle' => 'Beat competitor comparison pages with fresher answer blocks.',
        'reason' => 'CompetitorOS ranks with comparison content and Argusly has no equivalent.',
        'competitor_evidence' => [
            ['url' => 'https://competitorios.test/ai-visibility', 'title' => 'AI visibility guide'],
        ],
        'argusly_coverage' => ['status' => 'missing'],
        'normalized_payload' => ['source' => 'competitor_intelligence', 'gap' => 'comparison'],
    ], $overrides));
}

it('maps competitor opportunities into canonical opportunity signals with preserved evidence', function (): void {
    $opportunity = competitorPromotionOpportunity();

    $result = app(CompetitorContentOpportunitySignalPromotionService::class)->promote($opportunity, dryRun: false);
    $signal = $result->signal;

    expect($result->status)->toBe('created')
        ->and($signal)->toBeInstanceOf(OpportunitySignal::class)
        ->and($signal->source)->toBe(OpportunitySignalSource::COMPETITOR_INTELLIGENCE)
        ->and($signal->category)->toBe(OpportunityCategory::CONTENT_GAP)
        ->and($signal->topic)->toBe('AI visibility comparison')
        ->and($signal->entity)->toBe('CompetitorOS')
        ->and((float) $signal->signal_strength)->toBe(88.0)
        ->and((float) $signal->confidence)->toBe(76.0)
        ->and($signal->metadata)->toMatchArray([
            'source_type' => 'competitor_content_opportunity',
            'source_model' => CompetitorContentOpportunity::class,
            'source_id' => (string) $opportunity->id,
            'site_competitor_id' => (string) $opportunity->site_competitor_id,
            'recommended_actions' => [
                'implementation_guide',
                'Beat competitor comparison pages with fresher answer blocks.',
            ],
        ])
        ->and($signal->evidence[0])->toMatchArray([
            'type' => 'competitor_content_opportunity',
            'source_id' => (string) $opportunity->id,
            'attackable_angle' => 'Beat competitor comparison pages with fresher answer blocks.',
            'competitor' => [
                'id' => (string) $opportunity->site_competitor_id,
                'name' => 'CompetitorOS',
                'domain' => 'competitorios.test',
            ],
            'competitor_evidence' => [
                ['url' => 'https://competitorios.test/ai-visibility', 'title' => 'AI visibility guide'],
            ],
        ]);
});

it('uses a stable source scoped dedupe hash and promotes idempotently', function (): void {
    $opportunity = competitorPromotionOpportunity();
    $promotion = app(CompetitorContentOpportunitySignalPromotionService::class);

    $hash = $promotion->dedupeHash($opportunity);
    $first = $promotion->promote($opportunity, dryRun: false);
    $second = $promotion->promote($opportunity->refresh(), dryRun: false);

    expect($promotion->dedupeHash($opportunity->refresh()))->toBe($hash)
        ->and($second->signal?->id)->toBe($first->signal?->id)
        ->and(OpportunitySignal::query()->where('workspace_id', $opportunity->workspace_id)->count())->toBe(1)
        ->and(Opportunity::query()->count())->toBe(0);
});

it('skips promotion when required context is missing', function (): void {
    $opportunity = competitorPromotionOpportunity([
        'client_site_id' => null,
        'site_competitor_id' => null,
        'topic' => null,
        'title' => '',
    ]);

    $result = app(CompetitorContentOpportunitySignalPromotionService::class)->promote($opportunity, dryRun: false);

    expect($result->skipped())->toBeTrue()
        ->and($result->reasons)->toContain('client_site_id', 'site_competitor_id', 'topic')
        ->and(OpportunitySignal::query()->count())->toBe(0);
});

it('keeps the backfill command dry run by default', function (): void {
    $opportunity = competitorPromotionOpportunity();

    $this->artisan('mos:promote-competitor-opportunity-signals', [
        '--workspace' => $opportunity->workspace_id,
    ])->assertSuccessful();

    expect(OpportunitySignal::query()->count())->toBe(0);
});

it('applies backfill command promotions with source id filtering and detects dry-run duplicates', function (): void {
    $opportunity = competitorPromotionOpportunity();
    competitorPromotionOpportunity();

    $this->artisan('mos:promote-competitor-opportunity-signals', [
        '--apply' => true,
        '--source-id' => $opportunity->id,
    ])->assertSuccessful();

    expect(OpportunitySignal::query()->count())->toBe(1)
        ->and(OpportunitySignal::query()->first()?->metadata['source_id'])->toBe((string) $opportunity->id)
        ->and(Opportunity::query()->count())->toBe(0);

    $this->artisan('mos:promote-competitor-opportunity-signals', [
        '--source-id' => $opportunity->id,
    ])
        ->expectsOutputToContain('duplicates')
        ->assertSuccessful();
});

it('validates promoted competitor signals and reports linked versus unclustered status', function (): void {
    $linkedOpportunity = competitorPromotionOpportunity(['topic' => 'AI answer engine comparison']);
    $unlinkedOpportunity = CompetitorContentOpportunity::factory()->create([
        'organization_id' => $linkedOpportunity->organization_id,
        'workspace_id' => $linkedOpportunity->workspace_id,
        'client_site_id' => $linkedOpportunity->client_site_id,
        'site_competitor_id' => $linkedOpportunity->site_competitor_id,
        'title' => 'Create an AI buying guide comparison',
        'topic' => 'AI buying guide comparison',
        'priority_score' => 82,
        'confidence_score' => 73,
        'impact_score' => 80,
        'dedupe_hash' => hash('sha256', $linkedOpportunity->workspace_id.'|AI buying guide comparison'),
    ]);
    $promotion = app(CompetitorContentOpportunitySignalPromotionService::class);

    $linked = $promotion->promote($linkedOpportunity, dryRun: false);
    $promotion->promote($unlinkedOpportunity, dryRun: false);

    $canonical = Opportunity::factory()->create([
        'organization_id' => $linkedOpportunity->organization_id,
        'workspace_id' => $linkedOpportunity->workspace_id,
        'client_site_id' => $linkedOpportunity->client_site_id,
        'topic' => 'AI answer engine comparison',
        'dedupe_hash' => hash('sha256', $linkedOpportunity->workspace_id.'|linked-competitor-test'),
    ]);

    DB::table('opportunity_signal_links')->insert([
        'id' => (string) str()->uuid(),
        'opportunity_id' => (string) $canonical->id,
        'opportunity_signal_id' => (string) $linked->signal?->id,
        'weight' => 1,
        'contribution' => json_encode(['source' => 'competitor_intelligence'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('mos:validate-competitor-opportunity-signals', [
        '--workspace' => $linkedOpportunity->workspace_id,
    ])
        ->expectsOutputToContain('Promoted competitor opportunity signal validation')
        ->expectsOutputToContain('eligible')
        ->expectsOutputToContain('linked')
        ->expectsOutputToContain('AI answer engine comparison')
        ->assertSuccessful();

    $report = app(CompetitorOpportunitySignalValidationService::class)->inspect([
        'workspace' => $linkedOpportunity->workspace_id,
    ]);

    expect(Opportunity::query()->where('workspace_id', $unlinkedOpportunity->workspace_id)->count())->toBe(1)
        ->and($report['summary']['linked'])->toBe(1)
        ->and($report['summary']['unclustered'])->toBe(1);
});

it('reports incomplete promoted competitor signals without creating opportunities', function (): void {
    $opportunity = competitorPromotionOpportunity();

    OpportunitySignal::query()->create([
        'organization_id' => $opportunity->organization_id,
        'workspace_id' => $opportunity->workspace_id,
        'client_site_id' => null,
        'source' => OpportunitySignalSource::COMPETITOR_INTELLIGENCE->value,
        'category' => OpportunityCategory::CONTENT_GAP->value,
        'topic' => null,
        'entity' => null,
        'signal_strength' => 55,
        'confidence' => 60,
        'observed_at' => now(),
        'metrics' => [],
        'evidence' => [],
        'metadata' => [
            'source_type' => 'competitor_content_opportunity',
            'source_model' => CompetitorContentOpportunity::class,
            'source_id' => (string) $opportunity->id,
        ],
        'dedupe_hash' => '',
    ]);

    $this->artisan('mos:validate-competitor-opportunity-signals', [
        '--workspace' => $opportunity->workspace_id,
    ])
        ->expectsOutputToContain('Incomplete or risky signals')
        ->expectsOutputToContain('site_missing')
        ->expectsOutputToContain('dedupe_hash_missing')
        ->expectsOutputToContain('topic_or_title_missing')
        ->expectsOutputToContain('competitor_context_missing')
        ->expectsOutputToContain('evidence_missing')
        ->assertSuccessful();

    expect(Opportunity::query()->count())->toBe(0);
});

it('feeds promoted competitor signals through the canonical opportunity intelligence path', function (): void {
    $opportunity = competitorPromotionOpportunity([
        'title' => 'Create a competitor comparison hub',
        'topic' => 'Competitor comparison hub',
    ]);

    app(CompetitorContentOpportunitySignalPromotionService::class)->promote($opportunity, dryRun: false);

    $result = app(OpportunityIntelligenceEngine::class)->run($opportunity->workspace);
    $canonical = Opportunity::query()->where('workspace_id', $opportunity->workspace_id)->firstOrFail();

    expect($result['created'])->toBe(1)
        ->and($canonical->signals()->count())->toBe(1)
        ->and($canonical->metadata['has_competitor_intelligence_input'])->toBeTrue()
        ->and($canonical->metadata['competitor_content_opportunity_ids'])->toContain((string) $opportunity->id)
        ->and($canonical->source_signal_summary['promoted_competitor_intelligence_count'])->toBe(1)
        ->and($canonical->content_opportunity_id)->toBeNull()
        ->and($canonical->agentic_marketing_opportunity_id)->toBeNull();
});
