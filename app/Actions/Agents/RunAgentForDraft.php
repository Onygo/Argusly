<?php

namespace App\Actions\Agents;

use App\Agents\AgentOrchestrator;
use App\Agents\Data\AgentContext;
use App\Agents\Drafts\DraftSmartSuggestionsAgent;
use App\Agents\Support\AgentTriggerType;
use App\Models\AgentRun;
use App\Models\Draft;
use App\Models\User;

class RunAgentForDraft
{
    public function __construct(
        private readonly AgentOrchestrator $orchestrator,
        private readonly DraftSmartSuggestionsAgent $agent,
    ) {
    }

    public function execute(Draft $draft, User $user): AgentRun
    {
        $draft->loadMissing('clientSite.workspace');

        $context = AgentContext::forDraft($draft, [
            'organization_id' => $draft->clientSite?->workspace?->organization_id,
            'workspace_id' => $draft->clientSite?->workspace_id,
            'user_id' => $user->id,
            'trigger_type' => AgentTriggerType::MANUAL,
            'trigger_source' => 'app.drafts.smart_suggestions',
            'metadata' => [
                'surface' => 'draft_detail',
            ],
        ]);

        $result = $this->orchestrator->run($this->agent, $context);

        return AgentRun::query()
            ->where('agent_key', $this->agent->key())
            ->where('draft_id', (string) $draft->id)
            ->where('started_at', $result->startedAt)
            ->latest('created_at')
            ->first()
            ?? AgentRun::query()
                ->where('agent_key', $this->agent->key())
                ->where('draft_id', (string) $draft->id)
                ->latest('created_at')
                ->firstOrFail();
    }
}
