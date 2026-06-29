<?php

use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunitySignalValidationService;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function agenticSignalValidationContext(string $slug = 'agentic-signal-validation'): array
{
    $organization = Organization::query()->create([
        'name' => 'Agentic Signal '.$slug,
        'slug' => 'agentic-signal-'.$slug.'-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Agentic Signal Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Agentic Signal Site '.$slug,
        'site_url' => 'https://'.$slug.'-'.Str::random(6).'.example.test',
        'allowed_domains' => [$slug.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Grow AI visibility',
        'goal' => 'Increase answer-led discovery',
        'locale' => 'en',
        'status' => 'active',
    ]);

    $legacy = AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'Improve AI visibility',
        'type' => AgenticMarketingOpportunityType::AiVisibility->value,
        'priority_score' => 84,
        'status' => AgenticMarketingOpportunityStatus::Open->value,
        'payload' => [
            'detector' => 'ai_visibility_gaps',
            'topic' => 'AI visibility',
            'signals' => ['topic_keyword' => 'AI visibility'],
            'score_explanation' => ['impact_score' => 86, 'confidence_score' => 78],
        ],
    ]);

    return compact('organization', 'workspace', 'site', 'objective', 'legacy');
}

function promotedAgenticSignal(array $context, array $overrides = []): OpportunitySignal
{
    /** @var Workspace $workspace */
    $workspace = $context['workspace'];
    /** @var ClientSite $site */
    $site = $context['site'];
    /** @var AgenticMarketingObjective $objective */
    $objective = $context['objective'];
    /** @var AgenticMarketingOpportunity $legacy */
    $legacy = $context['legacy'];
    $detector = $overrides['detector_key'] ?? 'ai_visibility_gaps';
    $dedupe = $overrides['dedupe_hash'] ?? hash('sha256', 'agentic-signal|'.$workspace->id.'|'.$legacy->id.'|'.Str::random(6));

    return OpportunitySignal::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $overrides['client_site_id'] ?? $site->id,
        'content_id' => $overrides['content_id'] ?? null,
        'source' => $overrides['source'] ?? OpportunitySignalSource::AI_CITATION_TRACKING->value,
        'category' => $overrides['category'] ?? OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value,
        'topic' => $overrides['topic'] ?? 'AI visibility',
        'entity' => $overrides['entity'] ?? 'Argusly',
        'signal_strength' => $overrides['signal_strength'] ?? 86,
        'confidence' => $overrides['confidence'] ?? 78,
        'observed_at' => $overrides['observed_at'] ?? now()->subHour(),
        'metrics' => $overrides['metrics'] ?? [
            'detector_key' => $detector,
            'impact_score' => 86,
            'urgency_score' => 74,
            'priority_score' => 84,
        ],
        'evidence' => array_key_exists('evidence', $overrides) ? $overrides['evidence'] : [
            'source' => 'agentic_marketing_detector',
            'detector_key' => $detector,
            'title' => 'Improve AI visibility',
            'opportunity_type' => AgenticMarketingOpportunityType::AiVisibility->value,
            'legacy_agentic_marketing_opportunity' => [
                'source_model' => AgenticMarketingOpportunity::class,
                'source_id' => (string) $legacy->id,
                'objective_id' => (string) $objective->id,
                'status' => AgenticMarketingOpportunityStatus::Open->value,
                'type' => AgenticMarketingOpportunityType::AiVisibility->value,
            ],
        ],
        'metadata' => array_key_exists('metadata', $overrides) ? $overrides['metadata'] : [
            'source_type' => AgenticOpportunitySignalValidationService::SOURCE_TYPE,
            'source_model' => AgenticMarketingOpportunity::class,
            'source_id' => (string) $legacy->id,
            'legacy_agentic_marketing_opportunity_id' => (string) $legacy->id,
            'objective_id' => (string) $objective->id,
            'detector_key' => $detector,
            'agentic_type' => AgenticMarketingOpportunityType::AiVisibility->value,
            'agentic_status' => AgenticMarketingOpportunityStatus::Open->value,
            'source_scoped_dedupe_key' => $dedupe,
            'promotion' => [
                'version' => 'agentic-opportunity-signal-promotion:v1',
                'phase' => '3E',
                'legacy_agentic_marketing_opportunity_id' => (string) $legacy->id,
                'objective_id' => (string) $objective->id,
                'detector_key' => $detector,
                'source_scoped_dedupe_key' => $dedupe,
            ],
        ],
        'dedupe_hash' => $dedupe,
    ]);
}

it('reports a valid promoted Agentic signal as eligible', function (): void {
    $context = agenticSignalValidationContext('eligible');
    $signal = promotedAgenticSignal($context);

    $report = app(AgenticOpportunitySignalValidationService::class)->inspect(['workspace' => (string) $context['workspace']->id]);
    $row = collect($report['rows'])->firstWhere('signal_id', (string) $signal->id);

    expect($report['summary']['total_promoted_agentic_signals'])->toBe(1)
        ->and($report['summary']['eligible'])->toBe(1)
        ->and($row['eligible_for_opportunity_intelligence'])->toBeTrue()
        ->and($row['evidence_complete'])->toBeTrue()
        ->and($row['metadata_complete'])->toBeTrue()
        ->and($row['source_row_exists'])->toBeTrue()
        ->and($row['blocked_reasons'])->toBe([]);
});

it('blocks eligibility when promoted Agentic metadata is incomplete', function (): void {
    $context = agenticSignalValidationContext('missing-metadata');
    $signal = promotedAgenticSignal($context, [
        'metadata' => [
            'source_type' => AgenticOpportunitySignalValidationService::SOURCE_TYPE,
            'source_model' => AgenticMarketingOpportunity::class,
        ],
    ]);

    $row = collect(app(AgenticOpportunitySignalValidationService::class)->inspect()['rows'])
        ->firstWhere('signal_id', (string) $signal->id);

    expect($row['eligible_for_opportunity_intelligence'])->toBeFalse()
        ->and($row['metadata_complete'])->toBeFalse()
        ->and($row['blocked_reasons'])->toContain('metadata_incomplete');
});

it('blocks eligibility when promoted Agentic evidence is incomplete', function (): void {
    $context = agenticSignalValidationContext('missing-evidence');
    $signal = promotedAgenticSignal($context, ['evidence' => []]);

    $row = collect(app(AgenticOpportunitySignalValidationService::class)->inspect()['rows'])
        ->firstWhere('signal_id', (string) $signal->id);

    expect($row['eligible_for_opportunity_intelligence'])->toBeFalse()
        ->and($row['evidence_complete'])->toBeFalse()
        ->and($row['blocked_reasons'])->toContain('evidence_incomplete');
});

it('reports stale legacy Agentic source rows', function (): void {
    $context = agenticSignalValidationContext('stale-source');
    $staleId = (string) Str::uuid();
    $signal = promotedAgenticSignal($context, [
        'metadata' => [
            'source_type' => AgenticOpportunitySignalValidationService::SOURCE_TYPE,
            'source_model' => AgenticMarketingOpportunity::class,
            'source_id' => $staleId,
            'legacy_agentic_marketing_opportunity_id' => $staleId,
            'objective_id' => (string) $context['objective']->id,
            'detector_key' => 'ai_visibility_gaps',
            'agentic_type' => AgenticMarketingOpportunityType::AiVisibility->value,
            'source_scoped_dedupe_key' => 'stale-dedupe',
        ],
        'evidence' => [
            'source' => 'agentic_marketing_detector',
            'detector_key' => 'ai_visibility_gaps',
            'legacy_agentic_marketing_opportunity' => [
                'source_model' => AgenticMarketingOpportunity::class,
                'source_id' => $staleId,
                'objective_id' => (string) $context['objective']->id,
            ],
        ],
    ]);

    $report = app(AgenticOpportunitySignalValidationService::class)->inspect();
    $row = collect($report['rows'])->firstWhere('signal_id', (string) $signal->id);

    expect($report['summary']['stale_source'])->toBe(1)
        ->and($row['stale_source_risk'])->toBeTrue()
        ->and($row['source_row_exists'])->toBeFalse()
        ->and($row['blocked_reasons'])->toContain('source_record_stale');
});

it('reports duplicate promoted Agentic signal risk', function (): void {
    $context = agenticSignalValidationContext('duplicate');
    $dedupe = hash('sha256', 'duplicate-agentic-signal');
    $first = promotedAgenticSignal($context, ['dedupe_hash' => $dedupe]);
    promotedAgenticSignal($context, ['dedupe_hash' => $dedupe]);

    $report = app(AgenticOpportunitySignalValidationService::class)->inspect();
    $row = collect($report['rows'])->firstWhere('signal_id', (string) $first->id);

    expect($report['summary']['duplicate_signal_risk'])->toBe(2)
        ->and($row['duplicate_signal_risk'])->toBeTrue()
        ->and($row['eligible_for_opportunity_intelligence'])->toBeFalse()
        ->and($row['blocked_reasons'])->toContain('duplicate_signal_risk');
});

it('reports linked and unlinked promoted Agentic signals', function (): void {
    $context = agenticSignalValidationContext('linked');
    $linked = promotedAgenticSignal($context);
    $unlinkedContext = agenticSignalValidationContext('unlinked');
    promotedAgenticSignal($unlinkedContext);
    $opportunity = Opportunity::factory()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
    ]);

    DB::table('opportunity_signal_links')->insert([
        'id' => (string) Str::uuid(),
        'opportunity_id' => (string) $opportunity->id,
        'opportunity_signal_id' => (string) $linked->id,
        'weight' => 0.86,
        'contribution' => json_encode(['source' => 'agentic'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(AgenticOpportunitySignalValidationService::class)->inspect();
    $row = collect($report['rows'])->firstWhere('signal_id', (string) $linked->id);

    expect($report['summary']['linked'])->toBe(1)
        ->and($report['summary']['unlinked_eligible'])->toBe(1)
        ->and($row['linked_to_canonical_opportunity'])->toBeTrue()
        ->and($row['linked_canonical_opportunity_ids'])->toContain((string) $opportunity->id);
});

it('renders diagnostics command output without creating opportunities', function (): void {
    $context = agenticSignalValidationContext('command');
    promotedAgenticSignal($context);

    $before = Opportunity::query()->count();
    $exitCode = Artisan::call('mos:validate-agentic-opportunity-signals', [
        '--workspace' => (string) $context['workspace']->id,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Promoted Agentic opportunity signal validation')
        ->and($output)->toContain('Total promoted Agentic signals: 1')
        ->and($output)->toContain('Eligible signals: 1')
        ->and($output)->toContain('Detector breakdown')
        ->and($output)->toContain('Category breakdown')
        ->and(Opportunity::query()->count())->toBe($before);
});

it('lets the existing OpportunityIntelligenceEngine consume promoted Agentic signals', function (): void {
    $context = agenticSignalValidationContext('engine');
    $signal = promotedAgenticSignal($context);

    $result = app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    expect($result['created'])->toBe(1)
        ->and($opportunity->signals()->whereKey($signal->id)->exists())->toBeTrue()
        ->and($opportunity->metadata['has_agentic_marketing_input'])->toBeTrue()
        ->and($opportunity->metadata['agentic_marketing_opportunity_ids'])->toContain((string) $context['legacy']->id)
        ->and($opportunity->metadata['agentic_objective_ids'])->toContain((string) $context['objective']->id)
        ->and($opportunity->source_signal_summary['promoted_agentic_marketing_count'])->toBe(1)
        ->and($opportunity->source_signal_summary['agentic_detector_keys'])->toContain('ai_visibility_gaps');
});

it('does not mutate Agentic rows, actions or execution pipelines during validation', function (): void {
    $context = agenticSignalValidationContext('read-only');
    promotedAgenticSignal($context);
    $user = User::factory()->create([
        'organization_id' => $context['organization']->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => $context['objective']->id,
        'opportunity_id' => $context['legacy']->id,
        'action_type' => 'update_content',
        'status' => 'proposed',
        'payload' => ['title' => 'Update content'],
    ]);
    $pipeline = AgenticMarketingExecutionPipeline::query()->create([
        'organization_id' => $context['organization']->id,
        'objective_id' => $context['objective']->id,
        'opportunity_id' => $context['legacy']->id,
        'requested_by' => $user->id,
        'status' => 'queued',
        'input' => ['source' => 'test'],
    ]);
    $legacyUpdatedAt = $context['legacy']->updated_at;
    $actionUpdatedAt = $action->updated_at;
    $pipelineUpdatedAt = $pipeline->updated_at;

    app(AgenticOpportunitySignalValidationService::class)->inspect();

    expect(Opportunity::query()->count())->toBe(0)
        ->and($context['legacy']->fresh()->updated_at->equalTo($legacyUpdatedAt))->toBeTrue()
        ->and($action->fresh()->updated_at->equalTo($actionUpdatedAt))->toBeTrue()
        ->and($pipeline->fresh()->updated_at->equalTo($pipelineUpdatedAt))->toBeTrue();
});
