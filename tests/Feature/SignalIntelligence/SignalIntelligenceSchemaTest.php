<?php

use App\Enums\SignalCategory;
use App\Enums\SignalEntityType;
use App\Enums\SignalScoreType;
use App\Enums\SignalSeverity;
use App\Enums\SignalSourceType;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\SignalEntity;
use App\Models\SignalEvent;
use App\Models\SignalMention;
use App\Models\SignalScore;
use App\Models\SignalSource;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function signalWorkspace(string $slug = 'signal-test-org'): Workspace
{
    $organization = Organization::query()->create([
        'name' => 'Signal Test Organization '.$slug,
        'slug' => $slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    return Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Signal Test Workspace '.$slug,
        'display_name' => 'Signal Test Workspace '.$slug,
    ]);
}

it('creates the signal intelligence schema', function (): void {
    foreach ([
        'signal_entities',
        'signal_sources',
        'signal_feed_items',
        'signal_mentions',
        'signal_events',
        'signal_detections',
        'signal_detection_links',
        'signal_scores',
        'signal_processing_runs',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
        expect(Schema::hasColumn($table, 'id'))->toBeTrue();
    }

    expect(Schema::hasColumns('signal_entities', [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'entity_type',
        'entity_key',
        'entity_name',
        'deleted_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('signal_events', [
        'category',
        'type',
        'severity',
        'status',
        'signal_strength',
        'confidence_score',
        'dedupe_hash',
        'deleted_at',
    ]))->toBeTrue();
});

it('casts enums and json fields on signal models', function (): void {
    $event = SignalEvent::factory()->create([
        'category' => SignalCategory::RISK,
        'type' => SignalType::RISK_REPUTATION,
        'severity' => SignalSeverity::HIGH,
        'status' => SignalStatus::DETECTED,
        'metadata' => ['scope' => 'test'],
    ]);

    $source = SignalSource::factory()->create([
        'type' => SignalSourceType::LLM_TRACKING,
        'status' => SignalStatus::PROCESSING,
    ]);

    $score = SignalScore::factory()->create([
        'score_type' => SignalScoreType::RISK_LEVEL,
        'breakdown' => ['risk' => 77],
    ]);

    expect($event->category)->toBe(SignalCategory::RISK);
    expect($event->type)->toBe(SignalType::RISK_REPUTATION);
    expect($event->severity)->toBe(SignalSeverity::HIGH);
    expect($event->status)->toBe(SignalStatus::DETECTED);
    expect($event->metadata)->toBe(['scope' => 'test']);
    expect($source->type)->toBe(SignalSourceType::LLM_TRACKING);
    expect($source->status)->toBe(SignalStatus::PROCESSING);
    expect($score->score_type)->toBe(SignalScoreType::RISK_LEVEL);
    expect($score->breakdown)->toBe(['risk' => 77]);
});

it('links entities mentions events and detections', function (): void {
    $mention = SignalMention::factory()->create();
    $event = SignalEvent::factory()->create([
        'organization_id' => $mention->organization_id,
        'workspace_id' => $mention->workspace_id,
        'signal_mention_id' => $mention->id,
        'signal_entity_id' => $mention->signal_entity_id,
    ]);
    $detection = SignalDetection::factory()->create([
        'organization_id' => $mention->organization_id,
        'workspace_id' => $mention->workspace_id,
    ]);

    $detection->events()->attach($event->id, [
        'id' => (string) Str::uuid(),
        'weight' => 0.75,
        'contribution' => ['reason' => 'test'],
    ]);

    expect($mention->signalEntity)->toBeInstanceOf(SignalEntity::class);
    expect($mention->events()->whereKey($event->id)->exists())->toBeTrue();
    expect($detection->events()->whereKey($event->id)->exists())->toBeTrue();
    expect($event->detections()->whereKey($detection->id)->exists())->toBeTrue();
});

it('enforces entity deduplication per workspace', function (): void {
    $workspace = signalWorkspace('signal-dedupe-org');

    SignalEntity::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'entity_type' => SignalEntityType::BRAND,
        'entity_key' => 'argusly',
        'entity_name' => 'Argusly',
    ]);

    expect(fn () => SignalEntity::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'entity_type' => SignalEntityType::BRAND,
        'entity_key' => 'argusly',
        'entity_name' => 'Argusly duplicate',
    ]))->toThrow(QueryException::class);

    $otherWorkspace = signalWorkspace('signal-dedupe-other-org');

    SignalEntity::query()->create([
        'organization_id' => $otherWorkspace->organization_id,
        'workspace_id' => $otherWorkspace->id,
        'entity_type' => SignalEntityType::BRAND,
        'entity_key' => 'argusly',
        'entity_name' => 'Argusly',
    ]);

    expect(SignalEntity::withoutGlobalScopes()->where('entity_key', 'argusly')->count())->toBe(2);
});

it('soft deletes signal records', function (): void {
    $entity = SignalEntity::factory()->create();
    $event = SignalEvent::factory()->create([
        'organization_id' => $entity->organization_id,
        'workspace_id' => $entity->workspace_id,
        'signal_entity_id' => $entity->id,
    ]);

    $entity->delete();
    $event->delete();

    expect(SignalEntity::query()->find($entity->id))->toBeNull();
    expect(SignalEntity::withoutGlobalScopes()->withTrashed()->find($entity->id))->not->toBeNull();
    expect(SignalEvent::query()->find($event->id))->toBeNull();
    expect(SignalEvent::withoutGlobalScopes()->withTrashed()->find($event->id))->not->toBeNull();
});

it('enforces policy organization ownership', function (): void {
    $workspace = signalWorkspace('signal-policy-org');
    $otherWorkspace = signalWorkspace('signal-policy-other-org');

    $user = User::factory()->create([
        'organization_id' => $workspace->organization_id,
        'role' => 'viewer',
        'approved_at' => now(),
        'active' => true,
    ]);

    $owner = User::factory()->create([
        'organization_id' => $workspace->organization_id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $event = SignalEvent::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
    ]);

    $otherEvent = SignalEvent::factory()->create([
        'organization_id' => $otherWorkspace->organization_id,
        'workspace_id' => $otherWorkspace->id,
    ]);

    expect(Gate::forUser($user)->allows('view', $event))->toBeTrue();
    expect(Gate::forUser($user)->allows('update', $event))->toBeFalse();
    expect(Gate::forUser($user)->allows('view', $otherEvent))->toBeFalse();
    expect(Gate::forUser($owner)->allows('update', $event))->toBeTrue();
});

it('applies tenant scope for authenticated users', function (): void {
    $workspace = signalWorkspace('signal-scope-org');
    $otherWorkspace = signalWorkspace('signal-scope-other-org');

    SignalEvent::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
    ]);
    SignalEvent::factory()->create([
        'organization_id' => $otherWorkspace->organization_id,
        'workspace_id' => $otherWorkspace->id,
    ]);

    $user = User::factory()->create([
        'organization_id' => $workspace->organization_id,
        'role' => 'viewer',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user);

    expect(SignalEvent::query()->count())->toBe(1);
    expect(SignalEvent::withoutGlobalScopes()->count())->toBe(2);
});

it('exposes the signal intelligence feature flag and config', function (): void {
    expect(config('features.signal_intelligence'))->toBeTrue();
    expect(config('signal_intelligence.enabled'))->toBeTrue();
    expect(config('signal_intelligence.queue'))->toBe('intelligence');
    expect(config('signal_intelligence.retention_days'))->toBe(180);
    expect(config('signal_intelligence.score_defaults.confidence'))->toBe(50);
});
