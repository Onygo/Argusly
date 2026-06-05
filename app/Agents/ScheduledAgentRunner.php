<?php

namespace App\Agents;

use App\Agents\Contracts\AgentInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Support\AgentTriggerType;
use App\Models\AgentRun;
use App\Models\Content;

class ScheduledAgentRunner
{
    public function __construct(
        private readonly AgentOrchestrator $orchestrator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function runForContent(
        AgentInterface $agent,
        Content $content,
        string $triggerSource,
        array $metadata = [],
        ?string $draftId = null,
        ?int $userId = null,
    ): ?AgentRun {
        $content->loadMissing('workspace', 'clientSite.workspace');

        $context = AgentContext::forContent($content, [
            'organization_id' => $content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id,
            'workspace_id' => $content->workspace_id,
            'site_id' => $content->client_site_id,
            'draft_id' => $draftId,
            'user_id' => $userId,
            'trigger_type' => AgentTriggerType::SCHEDULED,
            'trigger_source' => $triggerSource,
            'metadata' => $metadata,
        ]);

        $result = $this->orchestrator->run($agent, $context);

        return AgentRun::query()
            ->where('agent_key', $agent->key())
            ->where('content_id', (string) $content->id)
            ->where('trigger_type', AgentTriggerType::SCHEDULED->value)
            ->where('started_at', $result->startedAt)
            ->latest('created_at')
            ->first()
            ?? AgentRun::query()
                ->where('agent_key', $agent->key())
                ->where('content_id', (string) $content->id)
                ->where('trigger_type', AgentTriggerType::SCHEDULED->value)
                ->latest('created_at')
                ->first();
    }
}

