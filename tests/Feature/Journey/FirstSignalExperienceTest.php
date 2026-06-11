<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\BrandContext;
use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Journey\FirstValueExperienceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('features.agentic_marketing', true);
    Config::set('features.signal_intelligence', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function s14Context(string $slug = 'first-signal'): array
{
    $organization = Organization::query()->create([
        'name' => 'Sprint 14 '.$slug,
        'slug' => 'sprint-14-'.$slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Sprint 14 Workspace '.$slug,
        'display_name' => 'Sprint 14 Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Sprint 14 Site '.$slug,
        'site_url' => 'https://'.$slug.'.example.com',
        'base_url' => 'https://'.$slug.'.example.com',
        'allowed_domains' => [$slug.'.example.com'],
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    BrandContext::query()->create([
        'workspace_id' => $workspace->id,
        'raw_input' => 'Argusly tracks AI visibility and opportunity signals.',
        'source_type' => 'manual',
        'structured_json' => ['primary_topics' => ['AI visibility']],
    ]);

    SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Signal Rival',
        'domain' => 'signal-rival.example',
        'is_active' => true,
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function s14SignalEvent(Workspace $workspace, ClientSite $site, string $topic = 'AI visibility'): SignalEvent
{
    return SignalEvent::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => SignalCategory::BRAND_VISIBILITY->value,
        'type' => SignalType::BRAND_MENTIONED->value,
        'severity' => SignalSeverity::INFO->value,
        'status' => SignalStatus::DETECTED->value,
        'topic' => $topic,
        'entity_name' => 'Argusly',
        'entity_key' => 'argusly',
        'signal_strength' => 72,
        'confidence_score' => 81,
        'impact_score' => 62,
        'urgency_score' => 45,
        'observed_at' => now(),
        'evidence' => [],
        'metrics' => [],
        'metadata' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
    ]);
}

function s14Detection(Workspace $workspace, ClientSite $site): SignalDetection
{
    return SignalDetection::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => SignalDetection::CATEGORY_OPPORTUNITY_DETECTION,
        'type' => 'opportunity_candidate',
        'status' => SignalStatus::DETECTED->value,
        'title' => 'AI visibility opportunity signal',
        'summary' => 'Related signals point to a useful content opportunity.',
        'primary_topic' => 'AI visibility',
        'primary_entity' => 'Argusly',
        'severity' => SignalSeverity::MEDIUM->value,
        'priority_score' => 74,
        'confidence_score' => 83,
        'impact_score' => 70,
        'urgency_score' => 55,
        'risk_score' => 8,
        'opportunity_score' => 84,
        'score_breakdown' => [],
        'evidence_summary' => [],
        'recommended_actions' => [],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'metadata' => [],
    ]);
}

function s14Opportunity(Workspace $workspace, ClientSite $site): Opportunity
{
    return Opportunity::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::CONTENT_GAP->value,
        'status' => OpportunityStatus::OPEN->value,
        'title' => 'Create AI visibility comparison',
        'topic' => 'AI visibility',
        'summary' => 'A reviewed signal became an opportunity.',
        'priority_score' => 80,
        'confidence_score' => 78,
        'impact_score' => 75,
        'urgency_score' => 60,
        'effort_score' => 45,
        'score_breakdown' => [],
        'recommended_actions' => [],
        'evidence' => [],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

it('builds first signal detection and opportunity explanation states', function (): void {
    $context = s14Context('states');
    s14SignalEvent($context['workspace'], $context['site']);
    $detection = s14Detection($context['workspace'], $context['site']);
    $opportunity = s14Opportunity($context['workspace'], $context['site']);

    $service = app(FirstValueExperienceService::class);

    expect($service->firstSignalCard($context['workspace'])['title'])->toBe('Your first signal has been detected')
        ->and($service->detectionCard($detection)['title'])->toBe('Your first opportunity signal is ready for review')
        ->and($service->opportunityCard($opportunity)['title'])->toBe('Your first opportunity is ready')
        ->and($service->opportunityCard($opportunity)['why_detected'])->toBe('This opportunity was created from related signals that point to a topic worth acting on.');
});

it('shows professional celebration moments once per first milestone only', function (): void {
    $context = s14Context('celebrations');

    LlmTrackingQuery::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'name' => 'Visibility query',
        'query_text' => 'best AI visibility tools',
        'locale' => 'en',
        'frequency' => 'daily',
        'is_active' => true,
    ]);
    s14SignalEvent($context['workspace'], $context['site']);
    s14Detection($context['workspace'], $context['site']);
    s14Opportunity($context['workspace'], $context['site']);

    $titles = app(FirstValueExperienceService::class)
        ->celebrations($context['workspace'])
        ->pluck('title')
        ->all();

    expect($titles)->toContain('First Query Created', 'First Signal Found', 'First Detection Created', 'First Opportunity Created');

    s14SignalEvent($context['workspace'], $context['site'], 'AI rankings');

    expect(app(FirstValueExperienceService::class)->firstSignalCard($context['workspace']))->toBeNull()
        ->and(app(FirstValueExperienceService::class)->celebrations($context['workspace'])->pluck('title')->all())->not->toContain('First Signal Found');
});

it('keeps first value experience isolated by workspace', function (): void {
    $own = s14Context('own');
    $other = s14Context('other');

    s14SignalEvent($other['workspace'], $other['site']);
    s14Detection($other['workspace'], $other['site']);
    s14Opportunity($other['workspace'], $other['site']);

    $service = app(FirstValueExperienceService::class);

    expect($service->firstSignalCard($own['workspace']))->toBeNull()
        ->and($service->firstDetectionCard($own['workspace']))->toBeNull()
        ->and($service->firstOpportunityCard($own['workspace']))->toBeNull();
});

it('renders guided first signal and detection cards on Signal Intelligence', function (): void {
    $context = s14Context('signal-render');
    s14SignalEvent($context['workspace'], $context['site']);
    s14Detection($context['workspace'], $context['site']);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index', ['workspace' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Your first signal has been detected')
        ->assertSee('What happened?')
        ->assertSee('Your first opportunity signal is ready for review')
        ->assertSee('We found a recurring topic that may represent a growth opportunity.');
});

it('renders guided first opportunity card and avoids technical labels', function (): void {
    $context = s14Context('opportunity-render');
    s14SignalEvent($context['workspace'], $context['site']);
    s14Detection($context['workspace'], $context['site']);
    $opportunity = s14Opportunity($context['workspace'], $context['site']);

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->assertOk()
        ->assertSee('Your first opportunity is ready')
        ->assertSee('This opportunity was created from related signals that point to a topic worth acting on.')
        ->assertDontSee('OpportunitySignal')
        ->assertDontSee('SignalDetection')
        ->assertDontSee('SignalEvent');
});
