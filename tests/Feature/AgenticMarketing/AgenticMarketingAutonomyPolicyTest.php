<?php

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticMarketingActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeAgenticAutonomyTenant(string $slug = 'am-autonomy'): array
{
    $org = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => $slug . ' workspace',
        'organization_id' => $org->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Autonomy site',
        'site_url' => 'https://' . $slug . '.example.test',
        'allowed_domains' => [$slug . '.example.test'],
        'is_active' => true,
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'organization_id' => $org->id,
        'role' => 'admin',
        'active' => true,
        'approved_at' => now(),
    ]);

    return compact('org', 'workspace', 'site', 'user');
}

function makeAgenticAutonomyObjective(array $setup, array $attributes = []): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create(array_merge([
        'organization_id' => $setup['org']->id,
        'workspace_id' => $setup['workspace']->id,
        'client_site_id' => $setup['site']->id,
        'name' => 'Autonomy objective',
        'goal' => 'Safely automate low-risk AM work.',
        'locale' => 'en',
        'languages' => ['en'],
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'manual',
        'status' => 'active',
    ], $attributes));
}

function makeAgenticAutonomyContent(array $setup, array $attributes = []): Content
{
    return Content::query()->create(array_merge([
        'workspace_id' => $setup['workspace']->id,
        'client_site_id' => $setup['site']->id,
        'title' => 'Agentic autonomy guide',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
        'seo_title' => 'Old title',
        'seo_meta_description' => 'Old description',
        'schema_type' => 'Article',
        'auto_publish' => false,
    ], $attributes));
}

function makeAgenticAutonomyAction(AgenticMarketingObjective $objective, array $attributes = []): AgenticMarketingAction
{
    return AgenticMarketingAction::query()->create(array_merge([
        'objective_id' => $objective->id,
        'action_type' => 'update_meta',
        'status' => AgenticMarketingAction::STATUS_APPROVED,
        'estimated_credits' => 0,
        'payload' => [
            'planning' => ['risk_level' => 'low'],
            'recommendation' => 'Improve answer clarity.',
        ],
    ], $attributes));
}

it('keeps manual approval mode proposal-only by default', function () {
    $setup = makeAgenticAutonomyTenant('am-manual');
    $objective = makeAgenticAutonomyObjective($setup, ['approval_mode' => 'manual']);
    $content = makeAgenticAutonomyContent($setup);
    $action = makeAgenticAutonomyAction($objective, [
        'content_id' => $content->id,
        'payload' => [
            'content_id' => $content->id,
            'seo_title' => 'New AI visibility title',
            'seo_meta_description' => 'New AI visibility description.',
            'planning' => ['risk_level' => 'low'],
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user']);

    $content->refresh();
    $action->refresh();

    expect($content->seo_title)->toBe('Old title')
        ->and(data_get($action->result, 'service_used'))->toBe('safe_meta_proposal')
        ->and(data_get($action->result, 'autonomy.mode'))->toBe('propose_only')
        ->and(data_get($action->result, 'autonomy.approval_required'))->toBeTrue();
});

it('keeps approval-required mode proposal-only even for low-risk schema changes', function () {
    $setup = makeAgenticAutonomyTenant('am-approval-required');
    $objective = makeAgenticAutonomyObjective($setup, ['approval_mode' => 'approval_required']);
    $content = makeAgenticAutonomyContent($setup, ['schema_type' => 'Article']);
    $action = makeAgenticAutonomyAction($objective, [
        'action_type' => 'add_schema',
        'content_id' => $content->id,
        'payload' => [
            'content_id' => $content->id,
            'schema_type' => 'FAQPage',
            'planning' => ['risk_level' => 'low'],
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user']);

    expect($content->fresh()->schema_type)->toBe('Article')
        ->and(data_get($action->fresh()->result, 'service_used'))->toBe('safe_schema_proposal')
        ->and(data_get($action->fresh()->result, 'autonomy.approval_required'))->toBeTrue();
});

it('auto-applies low-risk metadata in policy-engine mode with rollback metadata', function () {
    $setup = makeAgenticAutonomyTenant('am-auto-meta');
    $objective = makeAgenticAutonomyObjective($setup, ['approval_mode' => 'policy_engine']);
    $content = makeAgenticAutonomyContent($setup);
    $action = makeAgenticAutonomyAction($objective, [
        'content_id' => $content->id,
        'payload' => [
            'content_id' => $content->id,
            'seo_title' => 'New AI visibility title',
            'seo_meta_description' => 'New AI visibility description.',
            'planning' => ['risk_level' => 'low'],
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user']);

    $content->refresh();
    $result = $action->fresh()->result;

    expect($content->seo_title)->toBe('New AI visibility title')
        ->and($content->seo_meta_description)->toBe('New AI visibility description.')
        ->and(data_get($result, 'service_used'))->toBe('policy_auto_metadata_apply')
        ->and(data_get($result, 'rollback.fields.seo_title'))->toBe('Old title')
        ->and(data_get($result, 'autonomy.mode'))->toBe('auto_apply');
});

it('supports dry-run mode without applying policy-engine changes or reserving credits', function () {
    $setup = makeAgenticAutonomyTenant('am-dry-run');
    $objective = makeAgenticAutonomyObjective($setup, ['approval_mode' => 'policy_engine']);
    $content = makeAgenticAutonomyContent($setup);
    $action = makeAgenticAutonomyAction($objective, [
        'content_id' => $content->id,
        'estimated_credits' => 12,
        'payload' => [
            'content_id' => $content->id,
            'seo_title' => 'Dry run title',
            'planning' => ['risk_level' => 'low'],
            'automation' => ['dry_run' => true],
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user']);

    $action->refresh();
    expect($content->fresh()->seo_title)->toBe('Old title')
        ->and(data_get($action->result, 'dry_run'))->toBeTrue()
        ->and(data_get($action->result, 'autonomy.mode'))->toBe('dry_run')
        ->and($action->credit_reservation_id)->toBeNull();
});

it('auto-creates low-risk refresh drafts in policy-engine mode without publishing', function () {
    $setup = makeAgenticAutonomyTenant('am-auto-refresh');
    $objective = makeAgenticAutonomyObjective($setup, ['approval_mode' => 'policy_engine']);
    $content = makeAgenticAutonomyContent($setup);
    $action = makeAgenticAutonomyAction($objective, [
        'action_type' => 'refresh_article',
        'content_id' => $content->id,
        'payload' => [
            'content_id' => $content->id,
            'recommendation' => 'Refresh answer quality.',
            'planning' => ['risk_level' => 'low'],
        ],
    ]);

    app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user']);

    $action->refresh();
    $draft = Draft::query()->find(data_get($action->result, 'created_draft_id'));

    expect($draft)->not->toBeNull()
        ->and($draft->status)->toBe('generated')
        ->and($content->fresh()->auto_publish)->toBeFalse()
        ->and(data_get($action->result, 'autonomy.mode'))->toBe('auto_create_draft');
});

it('generates answer block and internal link suggestions but keeps apply approval gated', function () {
    $setup = makeAgenticAutonomyTenant('am-suggestions');
    $objective = makeAgenticAutonomyObjective($setup, ['approval_mode' => 'policy_engine']);
    $content = makeAgenticAutonomyContent($setup);

    foreach (['add_answer_block', 'improve_internal_links'] as $type) {
        $action = makeAgenticAutonomyAction($objective, [
            'action_type' => $type,
            'content_id' => $content->id,
            'payload' => [
                'content_id' => $content->id,
                'planning' => ['risk_level' => 'low'],
            ],
        ]);

        app(AgenticMarketingActionExecutor::class)->execute($action, $setup['user']);

        expect(data_get($action->fresh()->result, 'autonomy.mode'))->toBe('generate_proposal')
            ->and(data_get($action->fresh()->result, 'autonomy.requires_approval_to_apply'))->toBeTrue();
    }
});
