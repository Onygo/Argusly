<?php

use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\CreditAction;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BriefProcessing\BriefToDraftService;
use App\Services\DraftGenerationService;
use App\Services\HumanContent\HumanContentGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates and attaches an editorial plan before queuing draft generation', function (): void {
    Queue::fake();

    $context = createEditorialPlanFlowContext();
    $draft = app(BriefToDraftService::class)->claimAndCreateDraft((string) $context['brief']->id);

    expect($draft)->not->toBeNull()
        ->and(data_get($draft->meta, 'editorial_plan.version'))->toBe('editorial_plan_v1')
        ->and(data_get($draft->meta, 'editorial_plan.central_thesis'))->toContain('Content quality')
        ->and(data_get($draft->meta, 'editorial_plan.primary_pattern.name'))->not->toBeEmpty()
        ->and(data_get($draft->meta, 'editorial_plan.section_intentions'))->toBeArray()
        ->and(data_get($draft->meta, 'editorial_plan.evidence_plan'))->toBeArray()
        ->and(data_get($draft->meta, 'structure'))->toBeNull();

    Queue::assertPushed(GenerateDraftJob::class);
});

it('uses the editorial plan in draft generation payload instead of a fixed requested structure', function (): void {
    Queue::fake();

    $context = createEditorialPlanFlowContext();
    $draft = app(BriefToDraftService::class)->claimAndCreateDraft((string) $context['brief']->id);

    $payload = app(DraftGenerationService::class)->buildGenerationPayloadForDraft($draft->fresh(['brief', 'clientSite.workspace']));

    expect($payload['user'])->toContain('EDITORIAL PLAN')
        ->and($payload['user'])->toContain('Central thesis')
        ->and($payload['user'])->toContain('Primary editorial pattern')
        ->and($payload['user'])->toContain((string) data_get($draft->meta, 'editorial_plan.primary_pattern.name'))
        ->and($payload['user'])->toContain('Section intentions')
        ->and($payload['user'])->not->toContain('Requested structure:')
        ->and($payload['user'])->not->toContain("- Opening\n- Main section")
        ->and($payload['user'])->not->toContain("- Main section\n- Practical examples");
});

it('requires a usable editorial plan before generated content can pass the publish gate', function (): void {
    Queue::fake();

    $context = createEditorialPlanFlowContext();
    $draft = app(BriefToDraftService::class)->claimAndCreateDraft((string) $context['brief']->id);
    $meta = (array) $draft->meta;
    $meta['human_content_score_after'] = 85;
    $meta['ai_fingerprint_score_after'] = 18;
    data_set($meta, 'human_content.after', [
        'human_content_score' => 85,
        'editorial_quality_score' => 82,
        'originality_score' => 81,
        'ai_fingerprint_score' => 18,
    ]);
    $draft->forceFill(['meta' => $meta])->save();

    expect(app(HumanContentGate::class)->evaluate($draft->fresh(), $draft->content)['passed'])->toBeTrue();

    $draft->forceFill(['meta' => array_diff_key((array) $draft->meta, ['editorial_plan' => true])])->save();

    $gate = app(HumanContentGate::class)->evaluate($draft->fresh(), $draft->content);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['status'])->toBe(HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW)
        ->and($gate['reasons'])->toContain('Generated article is missing a usable Editorial Plan.');
});

it('requires the human content publish gate before automation auto-publish can proceed', function (): void {
    Queue::fake();

    $context = createEditorialPlanFlowContext();
    $draft = app(BriefToDraftService::class)->claimAndCreateDraft((string) $context['brief']->id);

    $meta = (array) $draft->meta;
    $meta['human_content_score_after'] = 51;
    $meta['ai_fingerprint_score_after'] = 74;
    data_set($meta, 'human_content.after', [
        'human_content_score' => 51,
        'editorial_quality_score' => 52,
        'originality_score' => 54,
        'ai_fingerprint_score' => 74,
    ]);
    $draft->forceFill(['meta' => $meta])->save();

    $gate = app(HumanContentGate::class)->markDraft($draft->fresh(), $draft->content);

    expect($gate['passed'])->toBeFalse()
        ->and($gate['status'])->toBe(HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW)
        ->and($draft->fresh()->status)->toBe(HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW);
});

/**
 * @return array{organization:Organization,workspace:Workspace,site:ClientSite,user:User,content:Content,brief:Brief}
 */
function createEditorialPlanFlowContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Editorial Flow Org',
        'slug' => 'editorial-flow-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Editorial Flow Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Editorial Flow Site',
        'site_url' => 'https://editorial-flow.example.test',
        'allowed_domains' => ['editorial-flow.example.test'],
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Editorial User',
        'email' => 'editorial-flow-' . Str::random(6) . '@example.test',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $content = Content::query()->create([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Content quality planning',
        'primary_keyword' => 'content quality',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Content quality planning for AI search',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'content quality',
        'target_audience' => 'Content leads',
        'search_intent' => 'informational',
        'funnel_stage' => 'consideration',
        'unique_angle' => 'Quality comes from editorial judgment before generation',
        'key_points' => ['Planning should define evidence, rhythm, and reader takeaway before drafting.'],
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

    return [
        'organization' => $organization,
        'workspace' => $workspace,
        'site' => $site,
        'user' => $user,
        'content' => $content,
        'brief' => $brief,
    ];
}
