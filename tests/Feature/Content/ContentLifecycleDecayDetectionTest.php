<?php

use App\Enums\CampaignStatus;
use App\Enums\ContentDecayRiskLevel;
use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentRefreshTaskStatus;
use App\Enums\ContentRefreshTaskType;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\ContentAiVisibilitySnapshot;
use App\Models\ContentLifecycleAnalysis;
use App\Models\ContentPerformanceMetric;
use App\Models\ContentRefreshTask;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\ContentLifecycle\ContentLifecycleDecayEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates explainable lifecycle analyses and refresh tasks for decaying content', function () {
    $organization = Organization::query()->create([
        'name' => 'Lifecycle Decay Org',
        'slug' => 'lifecycle-decay-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Lifecycle Decay Workspace',
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Outdated AI visibility guide',
        'primary_keyword' => 'ai visibility',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'generation_mode' => 'balanced',
        'lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value,
        'content_health_score' => 31,
        'ai_visibility_score' => 28,
        'semantic_coverage_score' => 34,
        'freshness_score' => 22,
        'internal_link_score' => 24,
        'answer_block_score' => 15,
        'updated_at' => now()->subDays(250),
    ]);

    Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'AI visibility measurement playbook',
        'primary_keyword' => 'ai visibility',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'generation_mode' => 'balanced',
        'lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value,
    ]);

    Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'AI Visibility Recovery',
        'slug' => 'ai-visibility-recovery-'.Str::lower(Str::random(6)),
        'objective' => 'Recover decaying AI visibility content.',
        'status' => CampaignStatus::ACTIVE->value,
    ]);

    ContentAiVisibilitySnapshot::query()->create([
        'content_id' => $content->id,
        'provider' => 'openai',
        'visibility_score' => 61,
        'citation_count' => 8,
        'captured_at' => now()->subDays(30),
    ]);

    ContentAiVisibilitySnapshot::query()->create([
        'content_id' => $content->id,
        'provider' => 'openai',
        'visibility_score' => 24,
        'citation_count' => 1,
        'captured_at' => now(),
    ]);

    ContentPerformanceMetric::query()->create([
        'content_id' => $content->id,
        'views' => 120,
        'reads' => 18,
        'read_rate' => 0.15,
        'last_seen_at' => now(),
        'meta' => [
            'previous_read_rate' => 0.42,
            'views_decline_percent' => 48,
        ],
    ]);

    $analysis = app(ContentLifecycleDecayEngine::class)->analyze($content);

    expect($analysis)->toBeInstanceOf(ContentLifecycleAnalysis::class);
    expect($analysis->decay_risk_level)->toBe(ContentDecayRiskLevel::CRITICAL);
    expect($analysis->refresh_recommendations)->not->toBeEmpty();
    expect($analysis->campaign_reconnect_suggestions)->not->toBeEmpty();
    expect($analysis->related_content_suggestions)->not->toBeEmpty();
    expect($analysis->internal_linking_suggestions)->not->toBeEmpty();

    $content->refresh();
    expect($content->decay_risk_level)->toBe(ContentDecayRiskLevel::CRITICAL);
    expect($content->lifecycle_stage)->toBe(ContentLifecycleStatus::REFRESH_NEEDED);

    expect(ContentRefreshTask::query()->where('content_id', $content->id)->where('status', ContentRefreshTaskStatus::OPEN->value)->count())->toBeGreaterThan(0);
    expect(ContentRefreshTask::query()->where('content_id', $content->id)->where('type', ContentRefreshTaskType::RESTORE_AI_VISIBILITY->value)->exists())->toBeTrue();
});
