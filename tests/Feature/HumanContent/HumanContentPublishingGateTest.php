<?php

use App\Jobs\PublishToWordPressJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\CreditAction;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ContentAutomation\ContentAutomationArticleService;
use App\Services\CreditWalletService;
use App\Services\DraftGenerationService;
use App\Services\HumanContent\HumanContentGate;
use App\Services\HumanContent\HumanContentScoreService;
use App\Services\HumanContent\HumanizationService;
use App\Services\Publication\ContentPublicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('allows passing generated content to auto-publish', function (): void {
    Queue::fake();

    [, , $content, $draft] = makeHumanContentGateContext();
    $draft->forceFill(['meta' => humanContentGateMeta(84, 82, 80, 18)])->save();

    $dispatch = app(ContentPublicationService::class)->dispatchWordPressPublication($content->fresh(), $draft->fresh(), [
        'source' => 'test.auto_publish',
    ]);

    expect($dispatch['queued'])->toBeTrue()
        ->and($content->fresh()->publish_status)->toBe('publishing');

    Queue::assertPushed(PublishToWordPressJob::class);
});

it('blocks failing generated content from auto-publishing', function (): void {
    Queue::fake();

    [, , $content, $draft] = makeHumanContentGateContext();
    $draft->forceFill(['meta' => humanContentGateMeta(52, 54, 49, 78, severe: true)])->save();

    $dispatch = app(ContentPublicationService::class)->dispatchWordPressPublication($content->fresh(), $draft->fresh(), [
        'source' => 'test.auto_publish',
    ]);

    expect($dispatch['queued'])->toBeFalse()
        ->and($dispatch['skip_reason'])->toBe('human_content_gate_blocked')
        ->and($draft->fresh()->status)->toBe(HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW)
        ->and($content->fresh()->publish_status)->toBe(HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW)
        ->and(data_get($draft->fresh()->meta, 'human_content_gate.reasons'))->toContain('Human content score is below 70.');

    Queue::assertNotPushed(PublishToWordPressJob::class);
});

it('allows an explicit manual override for failing generated content publication', function (): void {
    Queue::fake();

    [$user, , $content, $draft] = makeHumanContentGateContext();
    $draft->forceFill(['meta' => humanContentGateMeta(52, 54, 49, 78, severe: true)])->save();

    $dispatch = app(ContentPublicationService::class)->dispatchWordPressPublication($content->fresh(), $draft->fresh(), [
        'source' => 'test.manual_publish',
        'human_content_override' => true,
        'user_id' => $user->id,
    ]);

    expect($dispatch['queued'])->toBeTrue()
        ->and($content->fresh()->publish_status)->toBe('publishing')
        ->and(data_get($draft->fresh()->meta, 'publish_gate_status'))->toBe(HumanContentGate::STATUS_PASSED)
        ->and(data_get($draft->fresh()->meta, 'human_content_gate.manual_override'))->toBeTrue()
        ->and(data_get($draft->fresh()->meta, 'human_content_gate_override.original_reasons'))->toContain('Human content score is below 70.');

    Queue::assertPushed(PublishToWordPressJob::class);
});

it('marks blocked automation items as needing editorial review without failing the run item', function (): void {
    Queue::fake([PublishToWordPressJob::class]);

    [$user, $site] = makeHumanContentGateContext();
    $automation = ContentAutomation::query()->create([
        'organization_id' => $user->organization_id,
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'name' => 'Human gate automation',
        'is_active' => true,
        'is_paused' => false,
        'mode' => 'chain',
        'publication_mode' => 'auto_publish',
        'generation_frequency_value' => 3,
        'generation_frequency_unit' => 'days',
        'next_run_at' => now()->subMinute(),
        'chain_size' => 1,
        'locale' => 'en',
        'locales' => ['en'],
        'topic_scope' => 'Editorial quality',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $run = ContentAutomationRun::query()->create([
        'automation_id' => (string) $automation->id,
        'organization_id' => (int) $automation->organization_id,
        'workspace_id' => (string) $automation->workspace_id,
        'client_site_id' => (string) $automation->client_site_id,
        'status' => 'running',
        'triggered_by' => 'manual',
        'started_at' => now(),
        'generated_content_ids' => [],
        'generated_draft_ids' => [],
        'published_content_ids' => [],
        'metadata' => [],
    ]);
    $item = ContentAutomationRunItem::query()->create([
        'automation_run_id' => (string) $run->id,
        'automation_id' => (string) $automation->id,
        'chain_index' => 1,
        'status' => ContentAutomationRunItem::STATUS_RUNNING,
        'locale' => 'en',
        'title' => 'Why generic content needs review',
    ]);

    app()->instance(DraftGenerationService::class, Mockery::mock(DraftGenerationService::class, function ($mock): void {
        $mock->shouldReceive('generateWithRepair')->once()->andReturn([
            'title' => 'Why generic content needs review',
            'content_html' => '<h1>Introduction</h1><p>In today\'s fast-paced digital world, businesses must leverage content.</p>',
            'provider' => 'openai',
            'model_used' => 'gpt-test',
            'usage' => ['total_tokens' => 100],
            'meta' => [],
        ]);
    }));
    app()->instance(HumanContentScoreService::class, Mockery::mock(HumanContentScoreService::class, function ($mock): void {
        $weak = humanContentScoreForGate(48, 50, 46, 82, false);
        $mock->shouldReceive('scoreForDraftHtml')->once()->andReturn($weak);
    }));
    app()->instance(HumanizationService::class, Mockery::mock(HumanizationService::class, function ($mock): void {
        $mock->shouldReceive('shouldHumanize')->once()->andReturnFalse();
    }));

    $result = app(ContentAutomationArticleService::class)->execute($automation->fresh(), $run->fresh(), [
        'title' => 'Why generic content needs review',
        'related_keywords' => ['generic content'],
        'search_intent' => 'informational',
        'funnel_stage' => 'consideration',
        'sequence' => 1,
    ], $user, $item->fresh());

    expect($result['item_status'])->toBe(ContentAutomationRunItem::STATUS_NEEDS_EDITORIAL_REVIEW)
        ->and($result['last_error_code'])->toBe('publish_gate_blocked')
        ->and($item->fresh()->status)->toBe(ContentAutomationRunItem::STATUS_NEEDS_EDITORIAL_REVIEW)
        ->and(data_get($item->fresh()->metadata, 'human_content_gate.status'))->toBe(HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW);
});

it('still allows manual draft saves when the gate fails', function (): void {
    [, , , $draft] = makeHumanContentGateContext();
    $draft->forceFill([
        'content_html' => '<h1>Saved draft</h1><p>This weak manual draft can still be saved for review.</p>',
        'meta' => humanContentGateMeta(42, 45, 44, 80),
    ])->save();

    $gate = app(HumanContentGate::class)->evaluate($draft->fresh(), $draft->content);

    expect($gate['passed'])->toBeFalse()
        ->and($draft->fresh()->content_html)->toContain('Saved draft')
        ->and($draft->fresh()->status)->not->toBe(HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW);
});

function makeHumanContentGateContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Human Gate Org',
        'slug' => 'human-gate-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'name' => 'Human Gate Workspace',
        'organization_id' => $organization->id,
    ]);
    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Human Gate Site',
        'site_url' => 'https://human-gate.example.com',
        'base_url' => 'https://human-gate.example.com',
        'allowed_domains' => ['human-gate.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
    $plan = Plan::query()->firstOrCreate(
        ['key' => 'human-gate-plan'],
        [
            'name' => 'Human Gate Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );
    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);
    CreditAction::query()->firstOrCreate(
        ['key' => 'content.article'],
        [
            'name' => 'Article Generation',
            'label_en' => 'Article Generation',
            'label_nl' => 'Artikel Generatie',
            'category' => 'content',
            'credits_cost' => 1,
            'is_active' => true,
        ]
    );
    app(CreditWalletService::class)->addCredits((string) $site->id, 20, CreditWalletService::TYPE_ADJUSTMENT, [
        'source' => 'human-content-gate-test',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Human Gate Content',
        'primary_keyword' => 'human gate',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'automation',
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
        'language' => 'en',
    ]);
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $user->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Human Gate Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
        'primary_keyword' => 'human gate',
        'search_intent' => 'informational',
        'funnel_stage' => 'consideration',
    ]);
    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Human Gate Draft',
        'seo_title' => 'Human Gate Draft',
        'seo_meta_description' => 'Human gate draft metadata.',
        'language' => 'en',
        'output_type' => 'kb_article',
        'content_html' => '<h1>Human Gate Draft</h1><p>Useful content with examples and judgment.</p>',
        'meta' => humanContentGateMeta(84, 82, 80, 18),
    ]);

    return [$user, $site, $content, $draft];
}

function humanContentGateMeta(int $human, int $editorial, int $originality, int $fingerprint, bool $severe = false): array
{
    return [
        'editorial_plan' => [
            'central_thesis' => 'Editorial judgment determines whether generated content is publishable.',
            'primary_pattern' => [
                'name' => 'Problem to Discovery',
                'article_movement' => 'Move from the quality problem to the editorial decision.',
            ],
        ],
        'human_content_score_after' => $human,
        'ai_fingerprint_score_after' => $fingerprint,
        'fingerprint_findings' => $severe ? [
            ['type' => 'generic_headings', 'severity' => 'high', 'message' => 'Generic heading detected.'],
        ] : [],
        'human_content' => [
            'after' => humanContentScoreForGate($human, $editorial, $originality, $fingerprint, $human >= 70 && $editorial >= 65 && $originality >= 65 && $fingerprint <= 45),
        ],
    ];
}

function humanContentScoreForGate(int $human, int $editorial, int $originality, int $fingerprint, bool $passed): array
{
    return [
        'status' => $passed ? 'pass' : 'fail',
        'passed' => $passed,
        'human_content_score' => $human,
        'editorial_quality_score' => $editorial,
        'originality_score' => $originality,
        'ai_fingerprint_score' => $fingerprint,
        'findings' => ['Human gate finding.'],
        'ai_fingerprint' => [
            'severity' => $fingerprint > 45 ? 'high' : 'low',
            'findings' => [
                ['type' => 'generic_headings', 'severity' => 'high', 'humanization_action' => 'Rewrite generic headings.'],
            ],
        ],
    ];
}
