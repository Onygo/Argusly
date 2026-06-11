<?php

use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\SignalProcessingRun;
use App\Models\SignalSource;
use App\Models\Workspace;
use App\Services\SignalIntelligence\BrandMonitoringDetectionService;
use App\Services\SignalIntelligence\CompetitorMonitoringDetectionService;
use App\Services\SignalIntelligence\RiskDetectionService;
use App\Services\SignalIntelligence\SignalDetectionLinker;
use App\Services\SignalIntelligence\SignalScoringEngine;
use App\Services\SignalIntelligence\TrendDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

function detectionWorkspace(string $slug): Workspace
{
    $organization = Organization::query()->create([
        'name' => 'Detection '.$slug,
        'slug' => 'detection-'.$slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    return Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Detection '.$slug,
        'display_name' => 'Detection '.$slug,
    ]);
}

function detectionEvent(Workspace $workspace, array $overrides = []): SignalEvent
{
    static $counter = 0;
    $counter++;

    return SignalEvent::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'category' => SignalCategory::BRAND_VISIBILITY->value,
        'type' => SignalType::BRAND_MENTIONED->value,
        'severity' => SignalSeverity::INFO->value,
        'status' => SignalStatus::DETECTED->value,
        'topic' => 'AI visibility',
        'entity_name' => 'Argusly',
        'entity_key' => 'argusly',
        'signal_strength' => 70,
        'confidence_score' => 80,
        'impact_score' => 60,
        'urgency_score' => 30,
        'risk_score' => 20,
        'opportunity_score' => 50,
        'observed_at' => now()->subDay(),
        'evidence' => [['type' => 'test', 'counter' => $counter]],
        'metrics' => [],
        'metadata' => [],
        'dedupe_hash' => hash('sha256', $workspace->id.'|detection-event|'.$counter),
    ], $overrides));
}

it('scores signal events deterministically', function (): void {
    $workspace = detectionWorkspace('scoring');
    $event = detectionEvent($workspace, [
        'signal_strength' => 90,
        'confidence_score' => 80,
        'impact_score' => 70,
        'urgency_score' => 60,
        'risk_score' => 50,
    ]);

    $engine = app(SignalScoringEngine::class);
    $score = $engine->calculateSignalStrength($event);
    $breakdown = $engine->breakdown(collect([$event]));

    expect($score)->toBeGreaterThan(0.0)
        ->and($score)->toBeLessThanOrEqual(100.0)
        ->and($breakdown)->toHaveKeys(['event_count', 'avg_signal_strength', 'confidence', 'brand_visibility', 'risk']);
});

it('creates brand monitoring detections and deduplicates the same event set', function (): void {
    $workspace = detectionWorkspace('brand');
    $from = now()->subDays(3);
    $to = now();

    detectionEvent($workspace, ['observed_at' => now()->subDay()]);
    detectionEvent($workspace, ['observed_at' => now()->subHours(12)]);

    $service = app(BrandMonitoringDetectionService::class);
    $first = $service->detect($workspace, null, $from, $to);
    $second = $service->detect($workspace, null, $from, $to);

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and(SignalDetection::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and($first->first()->events()->count())->toBe(2)
        ->and($first->first()->score_breakdown)->toHaveKey('brand_visibility')
        ->and($first->first()->evidence_summary)->not->toBeEmpty();
});

it('creates competitor monitoring detections', function (): void {
    $workspace = detectionWorkspace('competitor');

    detectionEvent($workspace, [
        'category' => SignalCategory::COMPETITOR_VISIBILITY->value,
        'type' => SignalType::COMPETITOR_MENTIONED->value,
        'entity_name' => 'CompetitorOS',
        'entity_key' => 'competitorios',
        'risk_score' => 75,
        'metrics' => ['competitor_share_score' => 80],
        'observed_at' => now()->subHours(4),
    ]);

    $detections = app(CompetitorMonitoringDetectionService::class)->detect($workspace, null, now()->subDays(2), now());

    expect($detections->pluck('type')->all())->toContain(SignalType::COMPETITOR_MENTIONED->value, 'share_of_voice_loss')
        ->and(SignalDetection::query()->where('category', SignalDetection::CATEGORY_COMPETITOR_MONITORING)->count())->toBe(2);
});

it('detects trends by comparing current and previous periods', function (): void {
    $workspace = detectionWorkspace('trend');
    $sourceA = SignalSource::factory()->create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id]);
    $sourceB = SignalSource::factory()->create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id]);

    detectionEvent($workspace, [
        'category' => SignalCategory::TREND->value,
        'type' => SignalType::TOPIC_TRENDING->value,
        'topic' => 'AI search',
        'signal_source_id' => $sourceA->id,
        'observed_at' => now()->subDays(3),
    ]);
    detectionEvent($workspace, [
        'category' => SignalCategory::TREND->value,
        'type' => SignalType::TOPIC_TRENDING->value,
        'topic' => 'AI search',
        'signal_source_id' => $sourceA->id,
        'observed_at' => now()->subDay(),
    ]);
    detectionEvent($workspace, [
        'category' => SignalCategory::TREND->value,
        'type' => SignalType::TOPIC_TRENDING->value,
        'topic' => 'AI search',
        'signal_source_id' => $sourceB->id,
        'observed_at' => now()->subHours(6),
    ]);

    $detections = app(TrendDetectionService::class)->detect($workspace, null, now()->subDays(2), now());

    expect($detections->pluck('type')->all())->toContain('topic_trending', 'topic_velocity', 'cross_source_repetition');
    expect($detections->firstWhere('type', 'topic_velocity')->score_breakdown['previous_event_count'])->toBe(1);
});

it('detects risk without requiring sentiment data', function (): void {
    $workspace = detectionWorkspace('risk');

    detectionEvent($workspace, [
        'category' => SignalCategory::RISK->value,
        'type' => SignalType::RISK_COMPETITOR_PRESSURE->value,
        'risk_score' => 85,
        'observed_at' => now()->subHour(),
    ]);

    $detections = app(RiskDetectionService::class)->detect($workspace, null, now()->subDays(2), now());

    expect($detections->pluck('type')->all())->toContain('competitor_pressure_rising')
        ->and($detections->first()->risk_score)->toBeGreaterThan(0);
});

it('links detection events without duplicate pivot rows and supports lifecycle helpers', function (): void {
    $workspace = detectionWorkspace('linking');
    $event = detectionEvent($workspace);
    $detection = SignalDetection::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'status' => SignalStatus::DETECTED->value,
    ]);

    $linker = app(SignalDetectionLinker::class);
    $linker->link($detection, $event, 0.8, ['reason' => 'test']);
    $linker->link($detection, $event, 0.8, ['reason' => 'test']);

    expect($detection->events()->count())->toBe(1)
        ->and($detection->refresh()->score_breakdown)->toHaveKey('event_count');

    $detection->markReviewing();
    $detection->markPublished();
    $detection->markResolved();
    $detection->archive();

    expect($detection->refresh()->status)->toBe(SignalStatus::ARCHIVED);
});

it('detect command respects feature flag and workspace isolation', function (): void {
    $workspace = detectionWorkspace('command');
    $other = detectionWorkspace('command-other');
    detectionEvent($workspace, ['observed_at' => now()->subHour()]);
    detectionEvent($other, ['observed_at' => now()->subHour()]);

    Config::set('features.signal_intelligence', false);
    $this->artisan('signal-intelligence:detect', ['--workspace' => $workspace->id])->assertSuccessful();
    expect(SignalDetection::query()->count())->toBe(0);

    Config::set('features.signal_intelligence', true);
    $this->artisan('signal-intelligence:detect', ['--workspace' => $workspace->id, '--category' => 'brand_monitoring'])->assertSuccessful();

    expect(SignalDetection::query()->where('workspace_id', $workspace->id)->count())->toBe(1)
        ->and(SignalDetection::query()->where('workspace_id', $other->id)->count())->toBe(0)
        ->and(SignalProcessingRun::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});
