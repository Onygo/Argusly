<?php

use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\AgenticMarketingObjective;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use App\Services\AgenticMarketing\AgenticContentSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('passes publishable content with a connected site and tenant-safe metadata', function () {
    [$workspace, $site] = makeAgenticContentSafetyTenant('safety-pass');
    $content = makeAgenticSafeContent($workspace, $site);

    $result = app(AgenticContentSafetyService::class)->evaluate($content, $site, $workspace);

    expect($result['status'])->toBe(AgenticContentSafetyService::STATUS_PASS)
        ->and($result['pass'])->toBeTrue()
        ->and($result['issues'])->toBe([]);
});

it('blocks serious pre-publication content issues', function () {
    [$workspace, $site] = makeAgenticContentSafetyTenant('safety-block');
    $content = makeAgenticSafeContent($workspace, $site, [], '');
    $content->setRawAttributes(array_merge($content->getAttributes(), [
        'title' => '',
        'language' => 'it',
        'schema_type' => '',
    ]), true);

    $result = app(AgenticContentSafetyService::class)->evaluate($content, $site, $workspace);
    $keys = collect($result['issues'])->pluck('key')->all();

    expect($result['status'])->toBe(AgenticContentSafetyService::STATUS_BLOCK)
        ->and($keys)->toContain('title_missing')
        ->and($keys)->toContain('body_missing')
        ->and($keys)->toContain('locale_invalid')
        ->and($keys)->toContain('schema_type_missing');
});

it('blocks tenant isolation and duplicate slug risks', function () {
    [$workspace, $site] = makeAgenticContentSafetyTenant('safety-tenant');
    [$otherWorkspace] = makeAgenticContentSafetyTenant('safety-other');
    makeAgenticSafeContent($otherWorkspace, $site, [
        'title' => 'Existing article',
        'publish_url_key' => 'shared-slug',
        'canonical_url_key' => 'shared-slug',
    ]);
    $content = makeAgenticSafeContent($otherWorkspace, $site, [
        'title' => 'Wrong workspace article',
        'publish_url_key' => 'shared-slug',
        'canonical_url_key' => 'shared-slug',
    ]);

    $result = app(AgenticContentSafetyService::class)->evaluate($content, $site, $workspace);
    $keys = collect($result['issues'])->pluck('key')->all();

    expect($result['status'])->toBe(AgenticContentSafetyService::STATUS_BLOCK)
        ->and($keys)->toContain('content_workspace_mismatch')
        ->and($keys)->toContain('duplicate_slug_risk');
});

it('allows guided publication to proceed to approval with warnings', function () {
    [$workspace, $site] = makeAgenticContentSafetyTenant('safety-guided');
    $content = makeAgenticSafeContent($workspace, $site, [
        'seo_canonical' => null,
    ]);

    $decision = app(AgenticApprovalGate::class)->forAction(
        AgenticApprovalGate::ACTION_PUBLISH_CONTENT,
        $workspace,
        ['site_id' => (string) $site->id, 'content_id' => (string) $content->id]
    );

    expect($decision['blocked'])->toBeFalse()
        ->and($decision['requires_approval'])->toBeTrue()
        ->and(data_get($decision, 'policy_snapshot.content_safety.status'))->toBe(AgenticContentSafetyService::STATUS_WARNING);
});

it('blocks autonomous publication on serious safety issues and stores the safety result on the run', function () {
    [$workspace, $site, $organization] = makeAgenticContentSafetyTenant('safety-autonomous');
    makeAgenticContentSafetyAutonomousSettings($workspace, $site);
    $content = makeAgenticSafeContent($workspace, $site, [
        'schema_type' => 'UnknownThing',
    ]);
    $objective = makeAgenticContentSafetyObjective($organization, $workspace, $site);
    $action = AgenticMarketingAction::query()->create([
        'objective_id' => (string) $objective->id,
        'content_id' => (string) $content->id,
        'action_type' => AgenticApprovalGate::ACTION_PUBLISH_CONTENT,
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 3,
        'payload' => [
            'content_id' => (string) $content->id,
            'client_site_id' => (string) $site->id,
        ],
    ]);

    $decision = app(AgenticApprovalGate::class)->forMarketingAction($action);
    app(AgenticActionRunLogger::class)->recordGateDecision($action, $decision);

    $run = AgenticActionRun::query()->where('action_id', $action->id)->firstOrFail();

    expect($decision['blocked'])->toBeTrue()
        ->and($run->status)->toBe(AgenticActionRun::STATUS_BLOCKED)
        ->and(data_get($run->policy_snapshot, 'content_safety.status'))->toBe(AgenticContentSafetyService::STATUS_BLOCK)
        ->and(data_get($run->policy_snapshot, 'content_safety.issues.0.key'))->toBe('schema_type_invalid');
});

function makeAgenticContentSafetyTenant(string $slug): array
{
    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => Str::headline($slug).' Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => Str::headline($slug).' Site',
        'site_url' => 'https://'.$slug.'.example.test',
        'base_url' => 'https://'.$slug.'.example.test',
        'allowed_domains' => [$slug.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
        'last_heartbeat_at' => now(),
    ]);

    return [$workspace, $site, $organization];
}

function makeAgenticSafeContent(Workspace $workspace, ClientSite $site, array $attributes = [], string $body = '<p>Useful article body with an internal link.</p>'): Content
{
    $content = Content::query()->create(array_merge([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Safe article',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'language' => 'en',
        'seo_canonical' => rtrim((string) $site->base_url, '/').'/safe-article',
        'schema_type' => 'Article',
        'publish_url_key' => 'safe-article-'.Str::lower(Str::random(6)),
        'canonical_url_key' => 'safe-article-'.Str::lower(Str::random(6)),
        'internal_links_meta' => [
            'applied_suggestions' => [
                ['url' => '/related-article', 'anchor' => 'related article'],
            ],
            'applied_count' => 1,
        ],
    ], $attributes));

    if ($body !== '') {
        $version = ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'type' => ContentVersion::TYPE_DRAFT,
            'body' => $body,
            'source' => ContentVersion::SOURCE_PUBLISHLAYER,
        ]);

        $content->update(['current_version_id' => (string) $version->id]);
    }

    return $content->fresh();
}

function makeAgenticContentSafetyAutonomousSettings(Workspace $workspace, ClientSite $site): AgenticMarketingExecutionSetting
{
    return AgenticMarketingExecutionSetting::query()->create(array_merge(
        AgenticMarketingExecutionSetting::defaultsFor($workspace)->getAttributes(),
        [
            'workspace_id' => (string) $workspace->id,
            'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_AUTONOMOUS,
            'autonomous_publication_enabled' => true,
            'require_approval_for_external_publication' => false,
            'require_approval_for_new_pages' => false,
            'max_autonomous_credits_per_month' => 500,
            'allowed_site_ids' => [(string) $site->id],
        ],
    ));
}

function makeAgenticContentSafetyObjective(Organization $organization, Workspace $workspace, ClientSite $site): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create([
        'organization_id' => (int) $organization->id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'Safety objective',
        'goal' => 'Publish safely.',
        'locale' => 'en',
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);
}
