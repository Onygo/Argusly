<?php

use App\Agents\Data\AgentContext;
use App\Agents\Support\AgentTriggerType;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds a draft context from helper constructor', function () {
    [$organization, $workspace, $site, $content, $draft] = makeAgentContextModels();

    $context = AgentContext::forDraft($draft, [
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'user_id' => 99,
        'target_locales' => ['nl', 'de', 'nl', ''],
        'trigger_type' => AgentTriggerType::EVENT,
        'trigger_source' => 'draft.updated',
        'metadata' => [
            'attempted_at' => now(),
            'status' => AgentTriggerType::DEBUG,
        ],
    ]);

    expect($context->organizationId)->toBe($organization->id)
        ->and($context->workspaceId)->toBe($workspace->id)
        ->and($context->siteId)->toBe($site->id)
        ->and($context->contentId)->toBe($content->id)
        ->and($context->draftId)->toBe($draft->id)
        ->and($context->userId)->toBe(99)
        ->and($context->sourceLocale)->toBe('en')
        ->and($context->targetLocales)->toBe(['nl', 'de'])
        ->and($context->triggerType)->toBe('event')
        ->and($context->triggerSource)->toBe('draft.updated')
        ->and($context->metadata['status'])->toBe('debug')
        ->and($context->metadata['attempted_at'])->toBeString();
});

it('builds content and site contexts from helper constructors', function () {
    [$organization, $workspace, $site, $content] = makeAgentContextModels();

    $content->setRelation('workspace', $workspace);
    $site->setRelation('workspace', $workspace);

    $contentContext = AgentContext::forContent($content, [
        'target_locales' => ['fr'],
    ]);

    $siteContext = AgentContext::forSite($site, [
        'trigger_type' => 'debug',
        'metadata' => ['batch' => 3],
    ]);

    expect($contentContext->organizationId)->toBe($organization->id)
        ->and($contentContext->workspaceId)->toBe($workspace->id)
        ->and($contentContext->siteId)->toBe($site->id)
        ->and($contentContext->contentId)->toBe($content->id)
        ->and($contentContext->sourceLocale)->toBe('en')
        ->and($contentContext->targetLocales)->toBe(['fr'])
        ->and($siteContext->organizationId)->toBe($organization->id)
        ->and($siteContext->workspaceId)->toBe($workspace->id)
        ->and($siteContext->siteId)->toBe($site->id)
        ->and($siteContext->triggerType)->toBe('debug')
        ->and($siteContext->metadata['batch'])->toBe(3);
});

function makeAgentContextModels(): array
{
    $organization = Organization::query()->create([
        'name' => 'Agent Context Org',
        'slug' => 'agent-context-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Agent Context Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Agent Context Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Agent Context Content',
        'language' => 'en',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => createBriefForAgentContext($site)->id,
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'title' => 'Agent Context Draft',
        'language' => 'en',
    ]);

    return [$organization, $workspace, $site, $content, $draft];
}

function createBriefForAgentContext(ClientSite $site): \App\Models\Brief
{
    return \App\Models\Brief::query()->create([
        'client_site_id' => $site->id,
        'title' => 'Agent Context Brief',
    ]);
}
