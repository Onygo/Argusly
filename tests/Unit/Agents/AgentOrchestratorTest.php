<?php

use App\Agents\AgentOrchestrator;
use App\Agents\Contracts\AgentInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentResult;
use App\Models\AgentRun;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('runs a supported agent and persists the run', function () {
    [$organization, $workspace, $site, $content, $draft] = makeOrchestratorScopeModels();
    $orchestrator = app(AgentOrchestrator::class);
    $context = new AgentContext(
        organizationId: $organization->id,
        workspaceId: $workspace->id,
        siteId: $site->id,
        contentId: $content->id,
        draftId: $draft->id,
        triggerType: 'manual',
    );

    $agent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'draft.smart-suggestions';
        }

        public function supports(AgentContext $context): bool
        {
            return $context->draftId !== null;
        }

        public function run(AgentContext $context): AgentResult
        {
            return AgentResult::success(
                agentKey: 'ignored-by-orchestrator',
                summary: 'Returned two suggestions.',
                suggestions: [
                    ['type' => 'refresh_recommendation'],
                    ['type' => 'link_opportunity'],
                ],
                metrics: [
                    'suggestion_count' => 2,
                ],
            );
        }
    };

    $result = $orchestrator->run($agent, $context);

    expect($result->status)->toBe('success')
        ->and($result->agentKey)->toBe('draft.smart-suggestions')
        ->and($result->summary)->toBe('Returned two suggestions.');

    $run = AgentRun::query()->sole();

    expect($run->agent_key)->toBe('draft.smart-suggestions')
        ->and($run->status->value)->toBe('success')
        ->and($run->draft_id)->toBe($draft->id)
        ->and($run->summary)->toBe('Returned two suggestions.')
        ->and(data_get($run->output_payload, 'metrics.suggestion_count'))->toBe(2)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('returns a skipped result for unsupported agents and persists it', function () {
    $orchestrator = app(AgentOrchestrator::class);
    $context = new AgentContext(triggerType: 'debug');

    $agent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'content.link-opportunities';
        }

        public function supports(AgentContext $context): bool
        {
            return false;
        }

        public function run(AgentContext $context): AgentResult
        {
            throw new RuntimeException('Should not run.');
        }
    };

    $result = $orchestrator->run($agent, $context);

    expect($result->status)->toBe('skipped')
        ->and($result->summary)->toBe('Agent does not support the provided context.');

    $run = AgentRun::query()->sole();

    expect($run->status->value)->toBe('skipped')
        ->and(data_get($run->output_payload, 'raw_payload.reason'))->toBe('unsupported_context');
});

it('captures agent exceptions and stores failures', function () {
    [$organization, $workspace, $site, $content] = makeOrchestratorScopeModels();
    $orchestrator = app(AgentOrchestrator::class);
    $context = new AgentContext(
        organizationId: $organization->id,
        workspaceId: $workspace->id,
        siteId: $site->id,
        contentId: $content->id,
        triggerType: 'event',
        triggerSource: 'content.updated',
    );

    $agent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'content.refresh-recommendations';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function run(AgentContext $context): AgentResult
        {
            throw new RuntimeException('Refresh signals unavailable.');
        }
    };

    $result = $orchestrator->run($agent, $context);

    expect($result->status)->toBe('failed')
        ->and($result->summary)->toBe('Agent execution failed.')
        ->and($result->warnings)->toContain('Refresh signals unavailable.');

    $run = AgentRun::query()->sole();

    expect($run->agent_key)->toBe('content.refresh-recommendations')
        ->and($run->status->value)->toBe('failed')
        ->and($run->error_message)->toBe('Refresh signals unavailable.')
        ->and(data_get($run->output_payload, 'warnings.0'))->toBe('Refresh signals unavailable.')
        ->and($run->finished_at)->not->toBeNull();
});

function makeOrchestratorScopeModels(): array
{
    $organization = Organization::query()->create([
        'name' => 'Agent Orchestrator Org',
        'slug' => 'agent-orchestrator-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Agent Orchestrator Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Agent Orchestrator Site',
        'site_url' => 'https://orchestrator.example.com',
        'allowed_domains' => ['orchestrator.example.com'],
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Agent Orchestrator Content',
        'language' => 'en',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => \App\Models\Brief::query()->create([
            'client_site_id' => $site->id,
            'title' => 'Agent Orchestrator Brief',
        ])->id,
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'title' => 'Agent Orchestrator Draft',
        'language' => 'en',
    ]);

    return [$organization, $workspace, $site, $content, $draft];
}
