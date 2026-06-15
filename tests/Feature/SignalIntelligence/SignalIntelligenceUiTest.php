<?php

use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalSourceType;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\SignalProcessingRun;
use App\Models\SignalSource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('features.signal_intelligence', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function signalUiContext(string $slug = 'main', string $role = 'owner'): array
{
    $organization = Organization::query()->create([
        'name' => 'Signal UI '.$slug,
        'slug' => 'signal-ui-'.$slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Signal Workspace '.$slug,
        'display_name' => 'Signal Workspace '.$slug,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => $role,
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Signal Site '.$slug,
        'site_url' => 'https://'.$slug.'.test',
        'base_url' => 'https://'.$slug.'.test',
        'allowed_domains' => [$slug.'.test'],
        'is_active' => true,
    ]);

    return compact('organization', 'workspace', 'user', 'site');
}

function signalUiDetection(Workspace $workspace, array $overrides = []): SignalDetection
{
    $source = SignalSource::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'type' => SignalSourceType::MANUAL->value,
        'name' => 'Manual source',
    ]);

    $event = SignalEvent::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $overrides['client_site_id'] ?? null,
        'signal_source_id' => $source->id,
        'category' => $overrides['event_category'] ?? SignalCategory::BRAND_VISIBILITY->value,
        'type' => $overrides['event_type'] ?? SignalType::BRAND_MENTIONED->value,
        'topic' => $overrides['primary_topic'] ?? 'AI visibility',
        'entity_name' => $overrides['primary_entity'] ?? 'Argusly',
        'observed_at' => $overrides['last_seen_at'] ?? now()->subHour(),
    ]);

    $detection = SignalDetection::factory()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $event->client_site_id,
        'category' => SignalDetection::CATEGORY_BRAND_MONITORING,
        'type' => 'brand_visibility_change',
        'status' => SignalStatus::DETECTED->value,
        'severity' => SignalSeverity::MEDIUM->value,
        'title' => 'Brand visibility movement',
        'summary' => 'Stored signal evidence indicates movement.',
        'primary_topic' => 'AI visibility',
        'primary_entity' => 'Argusly',
        'priority_score' => 82,
        'confidence_score' => 88,
        'last_seen_at' => now()->subHour(),
    ], collect($overrides)->except(['event_category', 'event_type'])->all()));

    $detection->events()->attach($event->id, [
        'id' => (string) Str::uuid(),
        'weight' => 0.8,
        'contribution' => ['test' => true],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $detection;
}

it('shows the index when the feature flag is enabled', function (): void {
    $context = signalUiContext('index');
    signalUiDetection($context['workspace'], ['client_site_id' => $context['site']->id]);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index'))
        ->assertOk()
        ->assertSee('Signal Intelligence')
        ->assertSee('Brand visibility movement')
        ->assertSee('How to use this screen')
        ->assertSee('Review next detection')
        ->assertSee('This looks actionable because the evidence points to a topic that could become an opportunity.')
        ->assertSee('Review');
});

it('does not present processed detections as open review work', function (): void {
    $context = signalUiContext('processed');
    signalUiDetection($context['workspace'], [
        'category' => SignalDetection::CATEGORY_OPPORTUNITY_DETECTION,
        'status' => SignalStatus::RESOLVED->value,
        'title' => 'Processed opportunity candidate',
        'opportunity_score' => 86,
        'priority_score' => 88,
        'resolved_at' => now(),
    ]);
    signalUiDetection($context['workspace'], [
        'status' => SignalStatus::DISMISSED->value,
        'title' => 'Dismissed high priority signal',
        'priority_score' => 91,
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index', ['workspace' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('All detections in this view are processed')
        ->assertSee('No open high priority detections.')
        ->assertSee('There is no user action waiting in this view.')
        ->assertSee('All detections shown here are already processed')
        ->assertDontSee('Review next detection');
});

it('hides the index when the feature flag is disabled', function (): void {
    $context = signalUiContext('flag-off');
    Config::set('features.signal_intelligence', false);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index'))
        ->assertNotFound();
});

it('only shows data from the selected user workspace', function (): void {
    $context = signalUiContext('own');
    $other = signalUiContext('other');
    signalUiDetection($context['workspace'], ['title' => 'Own workspace signal']);
    signalUiDetection($other['workspace'], ['title' => 'Other workspace signal']);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index'))
        ->assertOk()
        ->assertSee('Own workspace signal')
        ->assertDontSee('Other workspace signal');
});

it('filters detections by status, severity, score, entity and topic', function (): void {
    $context = signalUiContext('filters');
    signalUiDetection($context['workspace'], [
        'title' => 'Filtered signal',
        'status' => SignalStatus::REVIEWING->value,
        'severity' => SignalSeverity::HIGH->value,
        'primary_entity' => 'Argusly',
        'primary_topic' => 'AI search',
        'priority_score' => 91,
        'confidence_score' => 92,
    ]);
    signalUiDetection($context['workspace'], [
        'title' => 'Hidden low signal',
        'status' => SignalStatus::DETECTED->value,
        'severity' => SignalSeverity::LOW->value,
        'primary_entity' => 'Other',
        'primary_topic' => 'Legacy search',
        'priority_score' => 40,
        'confidence_score' => 45,
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index', [
            'status' => SignalStatus::REVIEWING->value,
            'severity' => SignalSeverity::HIGH->value,
            'score_min' => 80,
            'confidence_min' => 80,
            'entity_name' => 'Argusly',
            'topic' => 'AI search',
        ]))
        ->assertOk()
        ->assertSee('Filtered signal')
        ->assertDontSee('Hidden low signal');
});

it('shows a detection detail page', function (): void {
    $context = signalUiContext('show');
    $detection = signalUiDetection($context['workspace'], ['title' => 'Detail signal']);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.detections.show', $detection))
        ->assertOk()
        ->assertSee('Detail signal')
        ->assertSee('Score Breakdown')
        ->assertSee('Linked Signal Events');
});

it('hides unavailable detection lifecycle actions', function (): void {
    $context = signalUiContext('hidden-actions');
    $detection = signalUiDetection($context['workspace'], [
        'status' => SignalStatus::DISMISSED->value,
        'title' => 'Dismissed lifecycle signal',
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.detections.show', $detection))
        ->assertOk()
        ->assertSee('Dismissed lifecycle signal')
        ->assertDontSee(route('app.signal-intelligence.detections.review', $detection), false)
        ->assertDontSee(route('app.signal-intelligence.detections.dismiss', $detection), false)
        ->assertDontSee(route('app.signal-intelligence.detections.resolve', $detection), false);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index', ['workspace' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Dismissed lifecycle signal')
        ->assertDontSee(route('app.signal-intelligence.detections.review', $detection), false)
        ->assertDontSee(route('app.signal-intelligence.detections.dismiss', $detection), false)
        ->assertDontSee(route('app.signal-intelligence.detections.resolve', $detection), false);
});

it('blocks cross workspace detection access', function (): void {
    $context = signalUiContext('cross-a');
    $other = signalUiContext('cross-b');
    $detection = signalUiDetection($other['workspace']);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.detections.show', $detection))
        ->assertNotFound();
});

it('marks a detection as reviewing', function (): void {
    $context = signalUiContext('review');
    $detection = signalUiDetection($context['workspace']);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.review', $detection))
        ->assertRedirect(route('app.signal-intelligence.detections.show', $detection));

    expect($detection->refresh()->status)->toBe(SignalStatus::REVIEWING);
});

it('dismisses a detection', function (): void {
    $context = signalUiContext('dismiss');
    $detection = signalUiDetection($context['workspace']);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.dismiss', $detection))
        ->assertRedirect(route('app.signal-intelligence.detections.show', $detection));

    expect($detection->refresh()->status)->toBe(SignalStatus::DISMISSED);
});

it('resolves a detection', function (): void {
    $context = signalUiContext('resolve');
    $detection = signalUiDetection($context['workspace']);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.resolve', $detection))
        ->assertRedirect(route('app.signal-intelligence.detections.show', $detection));

    expect($detection->refresh()->status)->toBe(SignalStatus::RESOLVED)
        ->and($detection->resolved_at)->not->toBeNull();
});

it('rejects unavailable detection lifecycle transitions', function (): void {
    $context = signalUiContext('reject-actions');
    $detection = signalUiDetection($context['workspace'], [
        'status' => SignalStatus::DISMISSED->value,
    ]);

    $this->actingAs($context['user'])
        ->from(route('app.signal-intelligence.detections.show', $detection))
        ->post(route('app.signal-intelligence.detections.resolve', $detection))
        ->assertRedirect(route('app.signal-intelligence.detections.show', $detection))
        ->assertSessionHasErrors('signal_intelligence');

    expect($detection->refresh()->status)->toBe(SignalStatus::DISMISSED)
        ->and($detection->resolved_at)->toBeNull();
});

it('starts detection services during a manual run', function (): void {
    $context = signalUiContext('run');

    SignalEvent::factory()->create([
        'organization_id' => $context['workspace']->organization_id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'category' => SignalCategory::BRAND_VISIBILITY->value,
        'type' => SignalType::BRAND_MENTIONED->value,
        'topic' => 'Manual run topic',
        'entity_name' => 'Argusly',
        'observed_at' => now()->subHour(),
    ]);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.run'), [
            'category' => 'brand_monitoring',
            'site' => $context['site']->id,
        ])
        ->assertRedirect(route('app.signal-intelligence.index', ['site' => $context['site']->id]));

    expect(SignalProcessingRun::query()->where('workspace_id', $context['workspace']->id)->where('run_type', 'manual_detection')->exists())->toBeTrue()
        ->and(SignalDetection::query()->where('workspace_id', $context['workspace']->id)->count())->toBeGreaterThan(0);
});

it('explains when a manual run has no signal events to detect', function (): void {
    $context = signalUiContext('run-empty');

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.run'), [
            'category' => 'brand_monitoring',
            'site' => $context['site']->id,
        ])
        ->assertRedirect(route('app.signal-intelligence.index', [
            'workspace' => $context['workspace']->id,
            'site' => $context['site']->id,
        ]))
        ->assertSessionHas('status', 'No signal events found for this period. Run an AI Visibility check first, or widen the date/site filter, then run detection again.');

    $run = SignalProcessingRun::query()
        ->where('workspace_id', $context['workspace']->id)
        ->where('run_type', 'manual_detection')
        ->firstOrFail();

    expect($run->result['reason'] ?? null)->toBe('no_signal_events')
        ->and($run->detections_created)->toBe(0)
        ->and(SignalDetection::query()->where('workspace_id', $context['workspace']->id)->count())->toBe(0);
});

it('ingests existing AI Visibility runs before explaining that no signal events exist', function (): void {
    $context = signalUiContext('run-llm-ingest');

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'name' => 'Visibility query',
        'query_text' => 'best AI visibility tools',
        'target_brand' => 'Argusly',
        'locale' => 'en',
        'frequency' => 'daily',
        'is_active' => true,
    ]);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'provider' => 'openai',
        'model' => 'gpt-test',
        'status' => 'succeeded',
        'raw_response' => 'Argusly is mentioned for AI visibility.',
        'answer_text' => 'Argusly is mentioned for AI visibility.',
        'brand_mentioned' => true,
        'ai_visibility_score' => 72,
        'model_confidence_score' => 84,
    ]);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.run'), [
            'category' => 'brand_monitoring',
            'site' => $context['site']->id,
        ])
        ->assertRedirect(route('app.signal-intelligence.index', ['site' => $context['site']->id]));

    $run = SignalProcessingRun::query()
        ->where('workspace_id', $context['workspace']->id)
        ->where('run_type', 'manual_detection')
        ->firstOrFail();

    expect(SignalEvent::query()->where('workspace_id', $context['workspace']->id)->count())->toBeGreaterThan(0)
        ->and($run->result['reason'] ?? null)->not->toBe('no_signal_events')
        ->and($run->result['llm_tracking_ingestion']['runs_seen'] ?? 0)->toBe(1)
        ->and($run->result['llm_tracking_ingestion']['events_created'] ?? 0)->toBeGreaterThan(0);
});

it('denies review actions for unauthorized users', function (): void {
    $context = signalUiContext('viewer', 'viewer');
    $detection = signalUiDetection($context['workspace']);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.review', $detection))
        ->assertForbidden();
});

it('promotes a qualified detection to an opportunity signal', function (): void {
    $context = signalUiContext('promote');
    $detection = signalUiDetection($context['workspace'], [
        'status' => SignalStatus::REVIEWING->value,
        'category' => SignalDetection::CATEGORY_TREND_DETECTION,
        'type' => 'topic_velocity',
        'title' => 'Trend promotion signal',
    ]);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.promote', $detection))
        ->assertRedirect(route('app.agentic-marketing.intelligence.index', ['workspace_id' => $context['workspace']->id]));

    $signal = OpportunitySignal::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    expect($signal->source->value)->toBe('signal_intelligence')
        ->and($signal->category->value)->toBe('trend_opportunity')
        ->and($signal->metadata['signal_detection_id'])->toBe((string) $detection->id)
        ->and($signal->metadata['signal_detection_category'])->toBe(SignalDetection::CATEGORY_TREND_DETECTION)
        ->and($signal->metadata['linked_signal_event_ids'])->not->toBeEmpty()
        ->and($detection->refresh()->status)->toBe(SignalStatus::PUBLISHED)
        ->and($detection->metadata['opportunity_signal_id'])->toBe((string) $signal->id)
        ->and($detection->metadata['promoted_by'])->toBe((string) $context['user']->id);
});

it('does not promote dismissed resolved or archived detections', function (string $status): void {
    $context = signalUiContext('blocked-'.$status);
    $detection = signalUiDetection($context['workspace'], ['status' => $status]);

    $this->actingAs($context['user'])
        ->from(route('app.signal-intelligence.detections.show', $detection))
        ->post(route('app.signal-intelligence.detections.promote', $detection))
        ->assertRedirect(route('app.signal-intelligence.detections.show', $detection))
        ->assertSessionHasErrors('signal_intelligence');

    expect(OpportunitySignal::query()->where('workspace_id', $context['workspace']->id)->count())->toBe(0)
        ->and($detection->refresh()->status->value)->toBe($status);
})->with([
    SignalStatus::DISMISSED->value,
    SignalStatus::RESOLVED->value,
    SignalStatus::ARCHIVED->value,
]);

it('reuses the existing opportunity signal when promoted twice', function (): void {
    $context = signalUiContext('promote-dedupe');
    $detection = signalUiDetection($context['workspace'], ['status' => SignalStatus::DETECTED->value]);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.promote', $detection));

    $first = OpportunitySignal::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.promote', $detection->refresh()))
        ->assertRedirect(route('app.agentic-marketing.intelligence.index', ['workspace_id' => $context['workspace']->id]));

    expect(OpportunitySignal::query()->where('workspace_id', $context['workspace']->id)->count())->toBe(1)
        ->and(OpportunitySignal::query()->first()->id)->toBe($first->id);
});

it('blocks cross workspace promotion', function (): void {
    $context = signalUiContext('promote-cross-a');
    $other = signalUiContext('promote-cross-b');
    $detection = signalUiDetection($other['workspace']);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.promote', $detection))
        ->assertNotFound();
});

it('shows the promote button only when promotion is allowed', function (): void {
    $context = signalUiContext('button');
    $allowed = signalUiDetection($context['workspace'], [
        'title' => 'Allowed promote button',
        'status' => SignalStatus::DETECTED->value,
    ]);
    $published = signalUiDetection($context['workspace'], [
        'title' => 'Published promote button',
        'status' => SignalStatus::PUBLISHED->value,
    ]);
    $viewer = User::factory()->create([
        'organization_id' => $context['organization']->id,
        'role' => 'viewer',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.detections.show', $allowed))
        ->assertOk()
        ->assertSee('Promote to Opportunity');

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.detections.show', $published))
        ->assertOk()
        ->assertDontSee('Promote to Opportunity');

    $this->actingAs($viewer)
        ->get(route('app.signal-intelligence.detections.show', $allowed))
        ->assertOk()
        ->assertDontSee('Promote to Opportunity');
});

it('promotion route requires the signal intelligence feature flag', function (): void {
    $context = signalUiContext('promote-flag');
    $detection = signalUiDetection($context['workspace']);

    Config::set('features.signal_intelligence', false);

    $this->actingAs($context['user'])
        ->post(route('app.signal-intelligence.detections.promote', $detection))
        ->assertNotFound();
});
