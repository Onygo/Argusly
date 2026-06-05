<?php

namespace App\Jobs;

use App\Agents\AgentOrchestrator;
use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\Data\AgentContext;
use App\Enums\DraftImprovementAction;
use App\Models\Content;
use App\Models\ContentImprovementRun;
use App\Services\Aeo\AeoScoreService;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Content\ContentImprovementLifecycleLogger;
use App\Services\Content\ContentImprovementService;
use App\Services\Drafts\DraftIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateContentImprovementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 240;

    public function __construct(
        public string $runId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(
        DraftIntelligenceService $intelligence,
        ContentImprovementService $improvements,
        ContentImprovementLifecycleLogger $logger,
        AeoScoreService $aeoScoreService,
        ContentCacheInvalidationService $cacheInvalidation,
        AgentOrchestrator $agentOrchestrator,
        ContentRefreshAgent $contentRefreshAgent,
    ): void {
        $run = ContentImprovementRun::query()
            ->with(['content.workspace', 'content.clientSite.workspace', 'targetDraft'])
            ->find($this->runId);

        if (! $run || ! $run->content) {
            return;
        }

        $action = DraftImprovementAction::fromInput((string) $run->type);
        if (! $action) {
            $run->forceFill([
                'status' => ContentImprovementRun::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => 'Unsupported improvement type.',
                'diagnostics' => array_merge((array) ($run->diagnostics ?? []), [
                    'retry_count' => $this->attempts(),
                    'queue_name' => $this->queue,
                ]),
            ])->save();
            $logger->record($run->fresh(), 'FAILED', 'Improvement job failed.', [
                'reason' => 'unsupported_improvement_type',
            ]);

            return;
        }

        $sourceDraft = $improvements->ensureEditableDraftForContent($run->content, (int) ($run->created_by ?? 0));
        $targetDraft = $improvements->ensureTargetDraftForRun($run, $run->content, $sourceDraft, (int) ($run->created_by ?? 0));
        $beforeHtml = (string) ($sourceDraft->content_html ?? '');

        $run->forceFill([
            'status' => ContentImprovementRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'progress_percentage' => 25,
            'draft_id' => (string) $targetDraft->id,
            'target_draft_id' => (string) $targetDraft->id,
            'source_draft_id' => (string) $sourceDraft->id,
            'diagnostics' => array_merge((array) ($run->diagnostics ?? []), [
                'queue_name' => $this->queue,
                'retry_count' => $this->attempts(),
                'source_revision_hash' => $run->source_revision_hash,
            ]),
        ])->save();

        $logger->record($run->fresh(), 'STARTED', 'Improvement job started.', [
            'draft_id' => (string) $targetDraft->id,
            'retry_count' => $this->attempts(),
            'queue_name' => $this->queue,
        ]);

        try {
            $result = $intelligence->improveSection($sourceDraft->fresh(), $action->value);
            $payload = $improvements->buildResultPayload($beforeHtml, $result);
            $evaluation = $improvements->evaluateGeneratedResult($beforeHtml, $payload);
            $diagnostics = array_merge((array) ($run->diagnostics ?? []), [
                'elapsed_seconds' => (int) now()->diffInSeconds($run->started_at ?? now()),
                'retry_count' => $this->attempts(),
                'queue_name' => $this->queue,
                'tokens_used' => $result['tokens_used'] ?? null,
                'source_revision_hash' => $run->source_revision_hash,
                'output_revision_hash' => $this->normalizedHtmlHash((string) ($payload['content_html'] ?? '')),
                'no_changes_reason' => $evaluation['reason'] !== '' ? $evaluation['reason'] : null,
            ]);

            $run->forceFill([
                'status' => $evaluation['status'],
                'progress_percentage' => 100,
                'completed_at' => now(),
                'failed_at' => null,
                'error_message' => $evaluation['status'] === ContentImprovementRun::STATUS_NO_CHANGES ? $evaluation['reason'] : null,
                'result_payload' => $payload,
                'target_draft_id' => (string) $targetDraft->id,
                'draft_id' => (string) $targetDraft->id,
                'output_revision_hash' => $diagnostics['output_revision_hash'],
                'generated_summary' => $evaluation['summary'],
                'diff_summary' => $evaluation['diff_summary'],
                'diagnostics' => $diagnostics,
            ])->save();

            if ($evaluation['status'] === ContentImprovementRun::STATUS_COMPLETED) {
                $targetDraft->forceFill(
                    $improvements->draftUpdatesFromPayload($targetDraft, $payload, (string) $run->id)
                )->saveQuietly();

                $content = $run->content->fresh(['drafts', 'currentVersion', 'currentRevision', 'answerBlocks']) ?? $run->content;
                $beforeAeo = (int) ($content->aeo_score ?? 0);
                $afterAeo = $aeoScoreService->recalculate($content)['score'] ?? $beforeAeo;

                $run->forceFill([
                    'before_score' => $beforeAeo,
                    'after_score' => (int) $afterAeo,
                ])->save();

                $cacheInvalidation->invalidateContent($content, 'content_improvement.completed');
                $this->refreshRecommendationsForGeneratedDraft(
                    $content,
                    $targetDraft,
                    $run,
                    $agentOrchestrator,
                    $contentRefreshAgent,
                );
            }

            $logger->record(
                $run->fresh(),
                $evaluation['status'] === ContentImprovementRun::STATUS_NO_CHANGES ? 'NO_CHANGES' : 'COMPLETED',
                $evaluation['status'] === ContentImprovementRun::STATUS_NO_CHANGES
                    ? 'Improvement job finished without useful changes.'
                    : 'Improvement job completed.',
                [
                'change_summary' => $evaluation['summary'],
                'status' => $evaluation['status'],
                'queue_name' => $this->queue,
                ]
            );
        } catch (Throwable $exception) {
            $isFinalAttempt = $this->attempts() >= $this->tries;

            $run->forceFill([
                'status' => $isFinalAttempt
                    ? ContentImprovementRun::STATUS_FAILED
                    : ContentImprovementRun::STATUS_QUEUED,
                'progress_percentage' => $isFinalAttempt ? 100 : 15,
                'failed_at' => $isFinalAttempt ? now() : null,
                'error_message' => $isFinalAttempt ? $exception->getMessage() : null,
                'diagnostics' => array_merge((array) ($run->diagnostics ?? []), [
                    'elapsed_seconds' => (int) now()->diffInSeconds($run->started_at ?? now()),
                    'retry_count' => $this->attempts(),
                    'queue_name' => $this->queue,
                    'failure_reason' => $exception->getMessage(),
                ]),
            ])->save();

            $logger->record(
                $run->fresh(),
                $isFinalAttempt ? 'FAILED' : 'RETRY_QUEUED',
                $isFinalAttempt ? 'Improvement job failed.' : 'Improvement job will retry.',
                [
                    'failure_reason' => $exception->getMessage(),
                    'retry_count' => $this->attempts(),
                ]
            );

            throw $exception;
        }
    }

    private function refreshRecommendationsForGeneratedDraft(
        Content $content,
        \App\Models\Draft $targetDraft,
        ContentImprovementRun $run,
        AgentOrchestrator $agentOrchestrator,
        ContentRefreshAgent $contentRefreshAgent,
    ): void {
        $context = AgentContext::forContent($content, [
            'organization_id' => $content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id,
            'workspace_id' => $content->workspace_id,
            'site_id' => $content->client_site_id,
            'draft_id' => (string) $targetDraft->id,
            'user_id' => $run->created_by,
            'trigger_type' => 'event',
            'trigger_source' => 'content_improvement.completed',
            'metadata' => [
                'surface' => 'content_improvement',
                'content_improvement_run_id' => (string) $run->id,
                'source_revision_hash' => (string) ($run->output_revision_hash ?? $run->source_revision_hash ?? ''),
                'target_draft_id' => (string) $targetDraft->id,
            ],
        ]);

        $agentOrchestrator->run($contentRefreshAgent, $context);
    }

    private function normalizedHtmlHash(string $html): string
    {
        return sha1(trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? ''));
    }
}
