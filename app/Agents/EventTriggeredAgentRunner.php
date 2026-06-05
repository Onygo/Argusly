<?php

namespace App\Agents;

use App\Agents\Contracts\AgentInterface;
use App\Agents\Data\AgentContext;
use App\Agents\Support\AgentTriggerType;
use App\Models\AgentRun;
use App\Models\Content;
use App\Models\Draft;

class EventTriggeredAgentRunner
{
    public function __construct(
        private readonly AgentOrchestrator $orchestrator,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function runForDraft(
        AgentInterface $agent,
        Draft $draft,
        string $triggerSource,
        array $metadata = [],
        ?int $userId = null,
    ): ?AgentRun {
        $draft->loadMissing('clientSite.workspace', 'content');

        $context = AgentContext::forDraft($draft, [
            'organization_id' => $draft->clientSite?->workspace?->organization_id,
            'workspace_id' => $draft->clientSite?->workspace_id,
            'site_id' => $draft->client_site_id,
            'content_id' => $draft->content_id,
            'user_id' => $userId,
            'trigger_type' => AgentTriggerType::EVENT,
            'trigger_source' => $triggerSource,
            'metadata' => $metadata,
        ]);

        $result = $this->orchestrator->run($agent, $context);

        return $this->resolveRun(
            agentKey: $agent->key(),
            startedAt: $result->startedAt?->format(\DateTimeInterface::ATOM),
            draftId: (string) $draft->id,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function runForContent(
        AgentInterface $agent,
        Content $content,
        string $triggerSource,
        array $metadata = [],
        ?int $userId = null,
        ?string $draftId = null,
    ): ?AgentRun {
        $content->loadMissing('workspace', 'clientSite.workspace');

        $context = AgentContext::forContent($content, [
            'organization_id' => $content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id,
            'workspace_id' => $content->workspace_id,
            'site_id' => $content->client_site_id,
            'draft_id' => $draftId,
            'user_id' => $userId,
            'trigger_type' => AgentTriggerType::EVENT,
            'trigger_source' => $triggerSource,
            'metadata' => $metadata,
        ]);

        $result = $this->orchestrator->run($agent, $context);

        return $this->resolveRun(
            agentKey: $agent->key(),
            startedAt: $result->startedAt?->format(\DateTimeInterface::ATOM),
            contentId: (string) $content->id,
        );
    }

    private function resolveRun(
        string $agentKey,
        ?string $startedAt = null,
        ?string $draftId = null,
        ?string $contentId = null,
    ): ?AgentRun {
        $query = AgentRun::query()->where('agent_key', $agentKey);

        if ($draftId !== null && $draftId !== '') {
            $query->where('draft_id', $draftId);
        }

        if ($contentId !== null && $contentId !== '') {
            $query->where('content_id', $contentId);
        }

        if ($startedAt !== null && $startedAt !== '') {
            $run = (clone $query)->where('started_at', $startedAt)->latest('created_at')->first();

            if ($run) {
                return $run;
            }
        }

        return $query->latest('created_at')->first();
    }
}
