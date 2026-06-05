<?php

use App\Agents\AgentWorkflowOrchestrator;
use App\Agents\Contracts\AgentInterface;
use App\Agents\Contracts\AgentWorkflowInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentResult;
use App\Agents\Data\AgentWorkflowStep;
use App\Models\AgentRun;
use App\Models\AgentWorkflowRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('runs workflow agents in order and stores parent and child runs', function () {
    $firstAgent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'tests.workflow.first';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function run(AgentContext $context): AgentResult
        {
            return AgentResult::success(
                agentKey: $this->key(),
                summary: 'First step completed.',
            );
        }
    };

    $secondAgent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'tests.workflow.second';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function run(AgentContext $context): AgentResult
        {
            return AgentResult::success(
                agentKey: $this->key(),
                summary: 'Second step completed.',
            );
        }
    };

    $workflow = new class($firstAgent, $secondAgent) implements AgentWorkflowInterface
    {
        public function __construct(
            private readonly AgentInterface $firstAgent,
            private readonly AgentInterface $secondAgent,
        ) {
        }

        public function key(): string
        {
            return 'tests.workflow.ordered';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function steps(AgentContext $context): array
        {
            return [
                new AgentWorkflowStep('first_step', $this->firstAgent),
                new AgentWorkflowStep('second_step', $this->secondAgent),
            ];
        }
    };

    [$content] = makeWorkflowAgentContentContext('workflow-ordered');

    $context = AgentContext::forContent($content, [
        'trigger_source' => 'tests.workflow',
        'trigger_type' => 'event',
    ]);

    $result = app(AgentWorkflowOrchestrator::class)->run($workflow, $context);

    $workflowRun = AgentWorkflowRun::query()->sole();
    $agentRuns = AgentRun::query()->orderBy('created_at')->get();

    expect($result->status)->toBe('success')
        ->and($workflowRun->workflow_key)->toBe('tests.workflow.ordered')
        ->and(data_get($workflowRun->output_payload, 'steps.0.step_key'))->toBe('first_step')
        ->and(data_get($workflowRun->output_payload, 'steps.1.step_key'))->toBe('second_step')
        ->and($agentRuns)->toHaveCount(2)
        ->and($agentRuns->pluck('workflow_step_key')->all())->toBe(['first_step', 'second_step'])
        ->and($agentRuns->pluck('workflow_run_id')->unique()->all())->toBe([(string) $workflowRun->id]);
});

it('continues workflow execution after a handled failure when the step allows it', function () {
    $failingAgent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'tests.workflow.failing';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function run(AgentContext $context): AgentResult
        {
            throw new RuntimeException('Planned failure');
        }
    };

    $recoveryAgent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'tests.workflow.recovery';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function run(AgentContext $context): AgentResult
        {
            return AgentResult::success(
                agentKey: $this->key(),
                summary: 'Recovery step completed.',
            );
        }
    };

    $workflow = new class($failingAgent, $recoveryAgent) implements AgentWorkflowInterface
    {
        public function __construct(
            private readonly AgentInterface $failingAgent,
            private readonly AgentInterface $recoveryAgent,
        ) {
        }

        public function key(): string
        {
            return 'tests.workflow.recoverable_failure';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function steps(AgentContext $context): array
        {
            return [
                new AgentWorkflowStep('failing_step', $this->failingAgent, continueOnFailure: true),
                new AgentWorkflowStep('recovery_step', $this->recoveryAgent),
            ];
        }
    };

    [, $draft] = makeWorkflowAgentDraftContext('workflow-recoverable');

    $result = app(AgentWorkflowOrchestrator::class)->run($workflow, AgentContext::forDraft($draft));

    expect($result->status)->toBe('warning')
        ->and(data_get($result->metrics, 'failed_count'))->toBe(1)
        ->and(data_get($result->metrics, 'success_count'))->toBe(1)
        ->and(AgentRun::query()->count())->toBe(2)
        ->and(AgentRun::query()->orderBy('created_at')->get()->map(fn (AgentRun $run) => $run->status->value)->all())->toBe(['failed', 'success']);
});

it('stops workflow execution early when a step is not allowed to continue after failure', function () {
    $failingAgent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'tests.workflow.stopper';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function run(AgentContext $context): AgentResult
        {
            throw new RuntimeException('Stop here');
        }
    };

    $neverReachedAgent = new class implements AgentInterface
    {
        public function key(): string
        {
            return 'tests.workflow.never_reached';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function run(AgentContext $context): AgentResult
        {
            return AgentResult::success(
                agentKey: $this->key(),
                summary: 'This step should not run.',
            );
        }
    };

    $workflow = new class($failingAgent, $neverReachedAgent) implements AgentWorkflowInterface
    {
        public function __construct(
            private readonly AgentInterface $failingAgent,
            private readonly AgentInterface $neverReachedAgent,
        ) {
        }

        public function key(): string
        {
            return 'tests.workflow.early_stop';
        }

        public function supports(AgentContext $context): bool
        {
            return true;
        }

        public function steps(AgentContext $context): array
        {
            return [
                new AgentWorkflowStep('stop_step', $this->failingAgent, continueOnFailure: false),
                new AgentWorkflowStep('unreached_step', $this->neverReachedAgent),
            ];
        }
    };

    [$content] = makeWorkflowAgentContentContext('workflow-stop');

    $result = app(AgentWorkflowOrchestrator::class)->run($workflow, AgentContext::forContent($content));

    expect($result->status)->toBe('failed')
        ->and(AgentRun::query()->count())->toBe(1)
        ->and(data_get($result->metrics, 'executed_step_count'))->toBe(1)
        ->and(data_get($result->steps, '0.step_key'))->toBe('stop_step');
});

function makeWorkflowAgentContentContext(string $prefix): array
{
    $organization = Organization::query()->create([
        'name' => 'Workflow Agent Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Workflow Agent BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Workflow Agent Workspace',
        'organization_id' => $organization->id,
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Workflow Agent Site',
        'site_url' => 'https://' . $prefix . '.example.com',
        'base_url' => 'https://' . $prefix . '.example.com',
        'allowed_domains' => [$prefix . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $owner = User::query()->create([
        'name' => 'Workflow Agent Owner',
        'email' => $prefix . '+owner@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Workflow content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
        'primary_keyword' => 'workflow content',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    return [$content, $owner, $site, $workspace];
}

function makeWorkflowAgentDraftContext(string $prefix): array
{
    [$content, $owner, $site] = makeWorkflowAgentContentContext($prefix);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $owner->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Workflow brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'draft',
        'title' => 'Workflow draft',
        'output_type' => 'kb_article',
        'language' => 'en',
        'content_html' => '<p>Workflow draft body.</p>',
    ]);

    return [$content, $draft, $owner, $site];
}
