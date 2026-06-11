<?php

namespace Database\Seeders;

use App\Enums\SignalCategory;
use App\Enums\SignalEntityType;
use App\Enums\SignalSeverity;
use App\Enums\SignalScoreType;
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
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SignalIntelligenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'signal-intelligence-demo'],
            ['name' => 'Signal Intelligence Demo', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Signal Intelligence Demo'],
            ['display_name' => 'Signal Intelligence Demo']
        );

        $source = SignalSource::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'type' => SignalSourceType::MANUAL->value,
                'name' => 'Manual demo signals',
            ],
            [
                'organization_id' => $organization->id,
                'status' => SignalStatus::DETECTED->value,
                'config' => ['seeded' => true],
                'last_seen_at' => now(),
                'last_processed_at' => now(),
            ]
        );

        $entity = SignalEntity::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'entity_type' => SignalEntityType::BRAND->value,
                'entity_key' => 'argusly',
            ],
            [
                'organization_id' => $organization->id,
                'entity_name' => 'Argusly',
                'first_seen_at' => now()->subDays(3),
                'last_seen_at' => now(),
                'mention_count' => 1,
                'signal_count' => 1,
                'metadata' => ['seeded' => true],
            ]
        );

        $mention = SignalMention::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'dedupe_hash' => hash('sha256', 'signal-intelligence-demo-mention'),
            ],
            [
                'organization_id' => $organization->id,
                'signal_entity_id' => $entity->id,
                'source_type' => SignalSourceType::MANUAL->value,
                'mention_type' => SignalMention::TYPE_BRAND,
                'entity_type' => 'brand',
                'entity_name' => 'Argusly',
                'entity_key' => 'argusly',
                'context' => 'Demo mention for the Signal Intelligence foundation.',
                'confidence_score' => 90,
                'observed_at' => now(),
                'metadata' => ['seeded' => true],
            ]
        );

        $event = SignalEvent::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'dedupe_hash' => hash('sha256', 'signal-intelligence-demo-event'),
            ],
            [
                'organization_id' => $organization->id,
                'signal_source_id' => $source->id,
                'signal_mention_id' => $mention->id,
                'signal_entity_id' => $entity->id,
                'category' => SignalCategory::BRAND_VISIBILITY->value,
                'type' => SignalType::BRAND_MENTIONED->value,
                'severity' => SignalSeverity::INFO->value,
                'status' => SignalStatus::DETECTED->value,
                'topic' => 'AI visibility',
                'entity_name' => 'Argusly',
                'entity_key' => 'argusly',
                'signal_strength' => 70,
                'confidence_score' => 90,
                'impact_score' => 55,
                'urgency_score' => 35,
                'observed_at' => now(),
                'evidence' => [['label' => 'Seeded context', 'value' => 'Manual demo signal']],
                'metrics' => ['mentions' => 1],
                'metadata' => ['seeded' => true],
            ]
        );

        $detection = SignalDetection::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'dedupe_hash' => hash('sha256', 'signal-intelligence-demo-detection'),
            ],
            [
                'organization_id' => $organization->id,
                'category' => SignalDetection::CATEGORY_BRAND_MONITORING,
                'type' => SignalType::BRAND_MENTIONED->value,
                'status' => SignalStatus::DETECTED->value,
                'title' => 'Argusly brand signal detected',
                'summary' => 'Seeded detection for validating the Signal Intelligence foundation.',
                'primary_topic' => 'AI visibility',
                'primary_entity' => 'Argusly',
                'severity' => SignalSeverity::INFO->value,
                'priority_score' => 60,
                'confidence_score' => 90,
                'impact_score' => 55,
                'urgency_score' => 35,
                'risk_score' => 20,
                'opportunity_score' => 65,
                'score_breakdown' => ['confidence' => 90, 'impact' => 55],
                'evidence_summary' => ['Manual demo signal'],
                'recommended_actions' => ['Review seeded signal foundation data'],
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'metadata' => ['seeded' => true],
            ]
        );

        $detection->events()->syncWithoutDetaching([
            $event->id => ['id' => (string) Str::uuid(), 'weight' => 1, 'contribution' => ['seeded' => true]],
        ]);

        SignalScore::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'scope_type' => 'entity',
                'scope_key' => 'argusly',
                'score_type' => SignalScoreType::BRAND_VISIBILITY->value,
                'period_start' => now()->subWeek()->toDateString(),
                'period_end' => now()->toDateString(),
            ],
            [
                'organization_id' => $organization->id,
                'score' => 72,
                'previous_score' => 64,
                'delta' => 8,
                'breakdown' => ['mentions' => 1, 'confidence' => 90],
                'computed_at' => now(),
            ]
        );
    }
}
