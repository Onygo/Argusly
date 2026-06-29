<?php

use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunitySignalPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function agenticSignalPromotionFixture(): array
{
    $organization = Organization::query()->create([
        'name' => 'Agentic Signal Promotion Org',
        'slug' => 'agentic-signal-promotion-org-'.str()->random(8),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Agentic Signal Promotion Workspace',
        'display_name' => 'Agentic Signal Promotion Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Agentic Signal Promotion Site',
        'site_url' => 'https://agentic-signal-promotion.test',
        'base_url' => 'https://agentic-signal-promotion.test',
        'allowed_domains' => ['agentic-signal-promotion.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Signal promotion objective',
        'goal' => 'Promote Agentic detector signals',
        'locale' => 'en',
        'status' => 'active',
    ]);

    return [$organization, $workspace, $site, $objective];
}

function agenticSignalPromotionOpportunity(AgenticMarketingObjective $objective, array $overrides = []): AgenticMarketingOpportunity
{
    return AgenticMarketingOpportunity::query()->create(array_replace_recursive([
        'objective_id' => $objective->id,
        'title' => 'Improve AI visibility for answer content',
        'type' => 'ai_visibility',
        'priority_score' => 82,
        'status' => 'open',
        'payload' => [
            'detector' => 'ai_visibility_gaps',
            'client_site_id' => (string) $objective->client_site_id,
            'topic' => 'AI visibility',
            'reasoning' => 'Stored AI visibility evidence is weaker than the objective target.',
            'signals' => [
                'topic_keyword' => 'AI visibility',
                'locale' => 'en',
                'query_text' => 'best AI visibility platform',
            ],
            'score_explanation' => [
                'impact_score' => 86,
                'confidence_score' => 74,
                'effort_score' => 42,
            ],
        ],
    ], $overrides));
}

it('dry-runs signal promotion while the feature flag is disabled', function (): void {
    [, , , $objective] = agenticSignalPromotionFixture();
    $legacy = agenticSignalPromotionOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_signal_promotion' => false]);

    $result = app(AgenticOpportunitySignalPromotionService::class)->promote($legacy);

    expect($result->status)->toBe('would_create')
        ->and($result->dryRun)->toBeTrue()
        ->and($result->signalEligible())->toBeTrue()
        ->and(OpportunitySignal::query()->count())->toBe(0)
        ->and(Opportunity::query()->count())->toBe(0);
});

it('blocks apply unless the signal promotion feature flag is enabled', function (): void {
    [, , , $objective] = agenticSignalPromotionFixture();
    $legacy = agenticSignalPromotionOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_signal_promotion' => false]);

    $result = app(AgenticOpportunitySignalPromotionService::class)->promote($legacy, apply: true);

    expect($result->status)->toBe('blocked')
        ->and($result->dryRun)->toBeFalse()
        ->and($result->reasons)->toContain('feature_flag_disabled')
        ->and(OpportunitySignal::query()->count())->toBe(0);
});

it('creates an OpportunitySignal on flagged apply without touching legacy execution or canonical opportunities', function (): void {
    [, $workspace, , $objective] = agenticSignalPromotionFixture();
    $legacy = agenticSignalPromotionOpportunity($objective);
    $legacyUpdatedAt = $legacy->updated_at;

    config(['features.mos_agentic_marketing_opportunity_signal_promotion' => true]);

    $result = app(AgenticOpportunitySignalPromotionService::class)->promote($legacy, apply: true, operatorContext: [
        'actor_id' => 'operator-1',
    ]);

    $signal = $result->signal;

    expect($result->status)->toBe('created')
        ->and($signal)->toBeInstanceOf(OpportunitySignal::class)
        ->and((string) $signal->workspace_id)->toBe((string) $workspace->id)
        ->and((string) $signal->client_site_id)->toBe((string) $objective->client_site_id)
        ->and($signal->dedupe_hash)->toBe($result->mappingResult->dedupeKey)
        ->and($signal->metadata['legacy_agentic_marketing_opportunity_id'])->toBe((string) $legacy->id)
        ->and($signal->metadata['detector_key'])->toBe('ai_visibility_gaps')
        ->and($signal->metadata['agentic_type'])->toBe('ai_visibility')
        ->and($signal->metadata['agentic_status'])->toBe('open')
        ->and($signal->metadata['promotion']['version'])->toBe('agentic-opportunity-signal-promotion:v1')
        ->and($signal->metadata['promotion']['promoted_by'])->toBe('operator-1')
        ->and($signal->evidence['legacy_agentic_marketing_opportunity']['source_id'])->toBe((string) $legacy->id)
        ->and(Opportunity::query()->count())->toBe(0)
        ->and($legacy->fresh()->updated_at->equalTo($legacyUpdatedAt))->toBeTrue();
});

it('uses workspace and Phase 3B dedupe key for idempotent repeated promotion', function (): void {
    [, , , $objective] = agenticSignalPromotionFixture();
    $legacy = agenticSignalPromotionOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_signal_promotion' => true]);

    $first = app(AgenticOpportunitySignalPromotionService::class)->promote($legacy, apply: true);
    $second = app(AgenticOpportunitySignalPromotionService::class)->promote($legacy->refresh(), apply: true);

    expect($first->status)->toBe('created')
        ->and($second->status)->toBe('already_current')
        ->and($second->signalId())->toBe($first->signalId())
        ->and(OpportunitySignal::query()->count())->toBe(1);
});

it('updates an existing signal when non-dedupe signal evidence changes', function (): void {
    [, , , $objective] = agenticSignalPromotionFixture();
    $legacy = agenticSignalPromotionOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_signal_promotion' => true]);

    $first = app(AgenticOpportunitySignalPromotionService::class)->promote($legacy, apply: true);

    $legacy->forceFill([
        'payload' => array_replace_recursive($legacy->payload, [
            'score_explanation' => [
                'impact_score' => 91,
                'confidence_score' => 80,
                'effort_score' => 42,
            ],
        ]),
    ])->save();

    $second = app(AgenticOpportunitySignalPromotionService::class)->promote($legacy->refresh(), apply: true);

    expect($second->status)->toBe('updated')
        ->and($second->signalId())->toBe($first->signalId())
        ->and($second->signal?->dedupe_hash)->toBe($first->signal?->dedupe_hash)
        ->and($second->signal?->signal_strength)->toBe(91.0)
        ->and(OpportunitySignal::query()->count())->toBe(1);
});

it('blocks rows with missing or blocked mapping context', function (): void {
    [, , , $objective] = agenticSignalPromotionFixture();
    $legacy = agenticSignalPromotionOpportunity($objective, [
        'payload' => [
            'detector' => 'unknown_detector',
            'topic' => 'Unknown signal',
        ],
    ]);

    config(['features.mos_agentic_marketing_opportunity_signal_promotion' => true]);

    $result = app(AgenticOpportunitySignalPromotionService::class)->promote($legacy, apply: true);

    expect($result->status)->toBe('blocked')
        ->and($result->reasons)->toContain('detector_classification_missing_or_blocked')
        ->and(OpportunitySignal::query()->count())->toBe(0);
});

it('runs the promotion command as dry-run with the flag disabled', function (): void {
    [, $workspace, , $objective] = agenticSignalPromotionFixture();
    agenticSignalPromotionOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_signal_promotion' => false]);

    $this->artisan('mos:promote-agentic-opportunity-signals', [
        '--workspace' => (string) $workspace->id,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Dry run only')
        ->expectsOutputToContain('inspected count: 1')
        ->expectsOutputToContain('signal eligible count: 1')
        ->expectsOutputToContain('would create count: 1')
        ->expectsOutputToContain('dedupe samples:');

    expect(OpportunitySignal::query()->count())->toBe(0);
});

it('requires the feature flag for command apply', function (): void {
    [, $workspace, , $objective] = agenticSignalPromotionFixture();
    agenticSignalPromotionOpportunity($objective);

    config(['features.mos_agentic_marketing_opportunity_signal_promotion' => false]);

    $this->artisan('mos:promote-agentic-opportunity-signals', [
        '--workspace' => (string) $workspace->id,
        '--apply' => true,
    ])
        ->assertFailed()
        ->expectsOutputToContain('Apply blocked');

    expect(OpportunitySignal::query()->count())->toBe(0);
});
