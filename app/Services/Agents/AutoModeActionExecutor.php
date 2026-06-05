<?php

namespace App\Services\Agents;

use App\Actions\Content\CreateRefreshDraft;
use App\Agents\Data\AgentWorkflowResult;
use App\Models\AgentRun;
use App\Models\Content;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AutoModeActionExecutor
{
    public function __construct(
        private readonly AgentAutomationSettingsResolver $settingsResolver,
        private readonly CreateRefreshDraft $createRefreshDraft,
    ) {
    }

    public function handlePublishedContentWorkflow(Content $content, AgentWorkflowResult $result): void
    {
        $content->loadMissing('clientSite.workspace', 'workspace');

        if (! $this->settingsResolver->automaticRefreshDraftCreationEnabledForSite($content->clientSite)) {
            return;
        }

        $refreshRunId = collect($result->steps)
            ->firstWhere('step_key', 'refresh_recommendations')['agent_run_id'] ?? null;

        if (! is_string($refreshRunId) || trim($refreshRunId) === '') {
            return;
        }

        $run = AgentRun::query()
            ->whereKey($refreshRunId)
            ->where('content_id', (string) $content->id)
            ->first();

        if (! $run) {
            return;
        }

        $refreshScore = (int) data_get($run->output_payload, 'raw_payload.refresh_score', 0);
        $urgencyLevel = (string) data_get($run->output_payload, 'raw_payload.urgency_level', 'low');

        if ($urgencyLevel !== 'high' || $refreshScore < (int) config('content_refresh.thresholds.score_high', 65)) {
            return;
        }

        $actor = $this->resolveActor($content);
        if (! $actor) {
            Log::warning('Auto mode skipped refresh draft creation because no actor could be resolved.', [
                'content_id' => (string) $content->id,
                'agent_run_id' => (string) $run->id,
            ]);

            return;
        }

        try {
            $draft = $this->createRefreshDraft->execute($content, $run, $actor);
        } catch (RuntimeException $exception) {
            Log::warning('Auto mode skipped refresh draft creation.', [
                'content_id' => (string) $content->id,
                'agent_run_id' => (string) $run->id,
                'reason' => $exception->getMessage(),
            ]);

            $outputPayload = is_array($run->output_payload) ? $run->output_payload : [];
            data_set($outputPayload, 'raw_payload.auto_actions.refresh_draft_skipped', [
                'reason' => $exception->getMessage(),
                'skipped_at' => now()->toIso8601String(),
            ]);

            $run->forceFill([
                'output_payload' => $outputPayload,
            ])->save();

            return;
        }

        $outputPayload = is_array($run->output_payload) ? $run->output_payload : [];
        data_set($outputPayload, $draft->wasRecentlyCreated
            ? 'raw_payload.auto_actions.refresh_draft_created'
            : 'raw_payload.auto_actions.refresh_draft_reused', [
            'draft_id' => (string) $draft->id,
            'created_at' => now()->toIso8601String(),
            'actor_user_id' => (string) $actor->id,
        ]);

        $run->forceFill([
            'output_payload' => $outputPayload,
        ])->save();
    }

    private function resolveActor(Content $content): ?User
    {
        $candidateIds = collect([
            $content->updated_by,
            $content->created_by,
        ])
            ->map(fn (mixed $id): string => is_scalar($id) ? trim((string) $id) : '')
            ->filter()
            ->values();

        if ($candidateIds->isNotEmpty()) {
            $user = User::query()->whereIn('id', $candidateIds->all())->first();
            if ($user) {
                return $user;
            }
        }

        return User::query()
            ->where('organization_id', $content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id)
            ->orderByRaw("case when role = 'owner' then 0 when role = 'admin' then 1 else 2 end")
            ->orderBy('created_at')
            ->first();
    }
}
