<?php

namespace App\Actions\Agents;

use App\Agents\AgentOrchestrator;
use App\Agents\Data\AgentContext;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Agents\Support\AgentTriggerType;
use App\Models\AgentRun;
use App\Models\Content;
use App\Models\User;

class RunInternalLinkingForContent
{
    public function __construct(
        private readonly AgentOrchestrator $orchestrator,
        private readonly InternalLinkingAgent $agent,
    ) {
    }

    public function execute(Content $content, User $user): AgentRun
    {
        $content->loadMissing('workspace', 'clientSite.workspace');

        $context = AgentContext::forContent($content, [
            'organization_id' => $content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id,
            'workspace_id' => $content->workspace_id,
            'site_id' => $content->client_site_id,
            'user_id' => $user->id,
            'trigger_type' => AgentTriggerType::MANUAL,
            'trigger_source' => 'app.content.internal_linking',
            'metadata' => [
                'surface' => 'content_detail',
            ],
        ]);

        $result = $this->orchestrator->run($this->agent, $context);

        return AgentRun::query()
            ->where('agent_key', $this->agent->key())
            ->where('content_id', (string) $content->id)
            ->where('started_at', $result->startedAt)
            ->latest('created_at')
            ->first()
            ?? AgentRun::query()
                ->where('agent_key', $this->agent->key())
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->firstOrFail();
    }
}
