<?php

namespace App\Services\ContentAutomation;

use App\Enums\ContentAutomationRunStatus;
use App\Enums\ContentAutomationTriggerType;
use App\Exceptions\InsufficientCreditsException;
use App\Models\ClientSite;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\Draft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ContentAutomationOrchestrator
{
    public function __construct(
        private readonly ContentAutomationPlanner $planner,
        private readonly ContentAutomationArticleService $articleService,
        private readonly \App\Services\Credits\CreditWarningService $creditWarnings,
        private readonly AutomationRunItemStateService $itemStateService,
        private readonly AutomationFailureService $failureService,
    ) {}

    public function run(
        ContentAutomation $automation,
        ContentAutomationTriggerType $triggerType = ContentAutomationTriggerType::SCHEDULED,
        ?int $requestedByUserId = null,
    ): ContentAutomationRun {
        $automation->loadMissing([
            'workspace.companyProfile',
            'clientSite',
            'brandVoice',
            'teamPersona',
            'buyerPersona',
            'latestRun',
        ]);

        $skipReason = $automation->skipReason();

        if ($skipReason !== null) {
            $this->markCompletedIfNeeded($automation, $skipReason);

            Log::info('content_automation.skipped', [
                'automation_id' => (string) $automation->id,
                'reason' => $skipReason,
            ]);

            return ContentAutomationRun::query()->create([
                'automation_id' => (string) $automation->id,
                'organization_id' => (int) $automation->organization_id,
                'workspace_id' => (string) $automation->workspace_id,
                'client_site_id' => $automation->client_site_id,
                'status' => ContentAutomationRunStatus::SKIPPED->value,
                'triggered_by' => $triggerType->value,
                'started_at' => now(),
                'finished_at' => now(),
                'result_summary' => 'Automation skipped because it is not eligible to run.',
                'metadata' => [
                    'skip_reason' => $skipReason,
                ],
            ]);
        }

        $creditEvaluation = $this->creditWarnings->evaluateAutomation($automation);

        if (! (bool) ($creditEvaluation['can_run'] ?? false)) {
            $this->creditWarnings->syncWorkspaceWarning($automation->workspace);

            Log::warning('content_automation.blocked_low_credits', [
                'automation_id' => (string) $automation->id,
                'available_credits' => (int) ($creditEvaluation['available_credits'] ?? 0),
                'required_credits' => (int) ($creditEvaluation['required_credits'] ?? 0),
            ]);

            return ContentAutomationRun::query()->create([
                'automation_id' => (string) $automation->id,
                'organization_id' => (int) $automation->organization_id,
                'workspace_id' => (string) $automation->workspace_id,
                'client_site_id' => $automation->client_site_id,
                'status' => ContentAutomationRunStatus::FAILED->value,
                'triggered_by' => $triggerType->value,
                'started_at' => now(),
                'finished_at' => now(),
                'result_summary' => (string) ($creditEvaluation['user_safe_message'] ?? $creditEvaluation['message'] ?? 'Automation blocked because credits are too low.'),
                'error_message' => (string) ($creditEvaluation['user_safe_message'] ?? $creditEvaluation['message'] ?? 'Automation blocked because credits are too low.'),
                'metadata' => [
                    'skip_reason' => 'insufficient_credits',
                    'failure_pattern' => 'insufficient_credits',
                    'failure_code' => 'PL-CREDITS-INSUFFICIENT',
                    'failure_details' => [
                        'pattern' => 'insufficient_credits',
                        'error_code' => 'PL-CREDITS-INSUFFICIENT',
                        'required_credits' => (int) ($creditEvaluation['required_credits'] ?? 0),
                        'available_credits' => (int) ($creditEvaluation['available_credits'] ?? 0),
                        'user_safe_message' => (string) ($creditEvaluation['user_safe_message'] ?? $creditEvaluation['message'] ?? ''),
                        'admin_message' => sprintf(
                            "Required credits: %d\nAvailable credits: %d\nRun blocked before dispatch.",
                            (int) ($creditEvaluation['required_credits'] ?? 0),
                            (int) ($creditEvaluation['available_credits'] ?? 0),
                        ),
                    ],
                    'last_error_code' => 'insufficient_credits',
                    'last_error_message' => (string) ($creditEvaluation['user_safe_message'] ?? $creditEvaluation['message'] ?? ''),
                    'last_failure_stage' => 'generation',
                    'credit_evaluation' => $creditEvaluation,
                ],
            ]);
        }

        try {
            [$automation, $run, $wasResumed] = $this->startRun($automation, $triggerType, $requestedByUserId);

            Log::info('content_automation.run_started', array_merge($this->logContext($automation, $run), [
                'resumed' => $wasResumed,
                'attempt_count' => (int) $run->attempt_count,
            ]));

            $storedPlan = data_get($run->metadata, 'plan');
            $plan = $wasResumed && is_array($storedPlan) && is_array($storedPlan['articles'] ?? null)
                ? $storedPlan
                : $this->planner->plan($automation);
            $articles = (array) ($plan['articles'] ?? []);

            if (! $wasResumed || ! is_array($storedPlan)) {
                $run->forceFill([
                    'metadata' => array_merge(is_array($run->metadata) ? $run->metadata : [], [
                        'plan' => $plan,
                    ]),
                ])->save();
            }

            Log::info('content_automation.items_planned', array_merge($this->logContext($automation, $run), [
                'planned_count' => count($articles),
                'source_locale' => (string) ($plan['source_locale'] ?? $automation->sourceLocale()),
                'locales' => (array) ($plan['locales'] ?? $automation->configuredLocales()),
            ]));

            if ($articles === []) {
                return $this->finalizeRun(
                    $automation,
                    $run,
                    ContentAutomationRunStatus::SKIPPED,
                    'Planner produced no unique articles for this run.',
                    [
                        'plan' => $plan,
                        'skip_reason' => 'empty_plan',
                    ],
                );
            }

            $plannedItems = $this->itemStateService->createPlannedItems($automation, $run, $articles);
            $itemModels = $plannedItems['source_items'];
            $actor = $this->resolveActor($automation, $requestedByUserId);
            $items = [];
            $generatedDraftIds = [];
            $generatedContentIds = [];
            $publishedContentIds = [];
            $failureCount = 0;
            $partialCount = 0;
            $midRunStopReason = null;

            foreach ($articles as $offset => $articlePlan) {
                $automation->refresh();
                $sourceKey = (string) ($articlePlan['stable_key'] ?? $articlePlan['sequence'] ?? ($offset + 1));
                $item = $itemModels[$sourceKey] ?? null;

                if (($stopReason = $this->stopReasonForCurrentRun($automation)) !== null) {
                    $midRunStopReason = $stopReason;
                    if ($item instanceof ContentAutomationRunItem) {
                        $this->markItemSkipped($automation, $run, $item, $stopReason);
                    }
                    $items[] = array_merge($articlePlan, [
                        'status' => 'skipped',
                        'skip_reason' => $midRunStopReason,
                    ]);
                    break;
                }

                if ($item instanceof ContentAutomationRunItem) {
                    $resumedResult = $this->resumeExistingItemResult($automation, $run, $item, $articlePlan);
                    if (is_array($resumedResult)) {
                        $generatedDraftIds = array_values(array_unique(array_merge(
                            $generatedDraftIds,
                            array_filter([(string) ($resumedResult['draft_id'] ?? '')])
                        )));
                        $generatedContentIds = array_values(array_unique(array_merge(
                            $generatedContentIds,
                            array_filter([(string) ($resumedResult['content_id'] ?? '')])
                        )));
                        $publishedContentIds = array_values(array_unique(array_merge(
                            $publishedContentIds,
                            (array) ($resumedResult['published_content_ids'] ?? []),
                        )));
                        $items[] = array_merge($articlePlan, [
                            'status' => (string) ($resumedResult['item_status'] ?? ContentAutomationRunItem::STATUS_COMPLETED),
                            'result' => $resumedResult,
                            'reused_existing' => true,
                        ]);

                        continue;
                    }
                }

                try {
                    if ($item instanceof ContentAutomationRunItem) {
                        $item->forceFill([
                            'status' => ContentAutomationRunItem::STATUS_RUNNING,
                            'started_at' => now(),
                            'finished_at' => null,
                        ])->save();
                    }

                    Log::info('content_automation.generation_started', $this->itemLogContext($automation, $run, $item, $articlePlan));

                    $result = $this->articleService->execute($automation, $run, $articlePlan, $actor, $item);
                    $generatedDraftIds[] = (string) $result['draft_id'];
                    $generatedContentIds[] = (string) $result['content_id'];
                    $publishedContentIds = array_values(array_unique(array_merge(
                        $publishedContentIds,
                        (array) ($result['published_content_ids'] ?? []),
                    )));
                    $itemStatus = (string) ($result['item_status'] ?? ContentAutomationRunItem::STATUS_COMPLETED);
                    if ($itemStatus === ContentAutomationRunItem::STATUS_PARTIAL) {
                        $partialCount++;
                    }

                    if ($item instanceof ContentAutomationRunItem) {
                        $contentId = $this->existingContentId((string) $result['content_id']);
                        $draftId = $this->existingDraftId((string) $result['draft_id']);
                        $briefId = $this->existingBriefId((string) ($result['brief_id'] ?? ''));
                        if ($contentId === null && $itemStatus === ContentAutomationRunItem::STATUS_COMPLETED) {
                            $itemStatus = ContentAutomationRunItem::STATUS_FAILED;
                            $result['failure_stage'] = $result['failure_stage'] ?? 'persistence';
                            $result['last_error_code'] = $result['last_error_code'] ?? 'missing_content_record';
                            $result['last_error_message'] = $result['last_error_message'] ?? 'No persisted content record was found for this automation item.';
                            $failureCount++;
                        }

                        $item->forceFill([
                            'status' => $itemStatus,
                            'content_id' => $contentId,
                            'draft_id' => $draftId,
                            'brief_id' => $briefId,
                            'failure_stage' => $result['failure_stage'] ?? null,
                            'last_error_code' => $result['last_error_code'] ?? null,
                            'last_error_message' => $result['last_error_message'] ?? null,
                            'finished_at' => now(),
                            'metadata' => array_merge(is_array($item->metadata) ? $item->metadata : [], [
                                'result' => $result,
                            ]),
                        ])->save();

                        $this->itemStateService->recordSourceResult($automation, $run, $item, $result);
                    }

                    Log::info('content_automation.generation_succeeded', array_merge(
                        $this->itemLogContext($automation, $run, $item, $articlePlan),
                        [
                            'created_content_id' => (string) $result['content_id'],
                            'draft_id' => (string) $result['draft_id'],
                            'item_status' => $itemStatus,
                        ],
                    ));

                    $items[] = array_merge($articlePlan, [
                        'status' => $itemStatus,
                        'result' => $result,
                    ]);
                } catch (InsufficientCreditsException $exception) {
                    $this->creditWarnings->syncWorkspaceWarning($automation->workspace);

                    $failureCount++;
                    if ($item instanceof ContentAutomationRunItem) {
                        $this->markItemFailed($automation, $run, $item, $articlePlan, $exception, 'generation', 'insufficient_credits');
                    }
                    $items[] = array_merge($articlePlan, [
                        'status' => 'failed',
                        'error' => $exception->getMessage(),
                        'failure_type' => 'insufficient_credits',
                    ]);

                    Log::warning('content_automation.article_blocked_low_credits', [
                        'automation_id' => (string) $automation->id,
                        'automation_name' => (string) $automation->name,
                        'run_id' => (string) $run->id,
                        'item_id' => $item instanceof ContentAutomationRunItem ? (string) $item->id : null,
                        'available_credits' => $exception->available,
                        'required_credits' => $exception->required,
                    ]);

                    break;
                } catch (\Throwable $exception) {
                    $failureCount++;
                    if ($item instanceof ContentAutomationRunItem) {
                        $this->markItemFailed($automation, $run, $item, $articlePlan, $exception, $this->failureStageFromException($exception));
                    }
                    $items[] = array_merge($articlePlan, [
                        'status' => 'failed',
                        'error' => $exception->getMessage(),
                        'failure_type' => $exception::class,
                    ]);

                    Log::warning('content_automation.article_failed', [
                        'automation_id' => (string) $automation->id,
                        'automation_name' => (string) $automation->name,
                        'run_id' => (string) $run->id,
                        'item_id' => $item instanceof ContentAutomationRunItem ? (string) $item->id : null,
                        'sequence' => (int) ($articlePlan['sequence'] ?? 0),
                        'locale' => (string) ($articlePlan['target_locale'] ?? $automation->sourceLocale()),
                        'failure_stage' => $this->failureStageFromException($exception),
                        'exception_class' => $exception::class,
                        'message' => $exception->getMessage(),
                        'exception' => $exception,
                    ]);
                }
            }

            $truth = $this->runTruth($run->fresh(['items']) ?? $run);
            $successCount = (int) $truth['generated_count'];
            $failureCount = (int) $truth['failed_count'];
            $partialCount = max($partialCount, (int) $truth['partial_count']);
            $status = $this->statusFromTruth($truth, $midRunStopReason);

            $finalizedRun = $this->finalizeRun(
                $automation,
                $run,
                $status,
                $midRunStopReason !== null
                    ? $this->summaryForStoppedRun($successCount, $midRunStopReason)
                    : $this->summaryForRun($successCount, $failureCount, count($publishedContentIds), $partialCount),
                [
                    'plan' => $plan,
                    'items' => $items,
                    'stop_reason' => $midRunStopReason,
                ],
                $generatedDraftIds,
                $generatedContentIds,
                $publishedContentIds,
            );

            if (in_array($finalizedRun->status?->value ?? (string) $finalizedRun->status, [
                ContentAutomationRunStatus::FAILED->value,
                ContentAutomationRunStatus::PARTIAL->value,
            ], true)) {
                $automation->forceFill([
                    'last_failure_message' => $finalizedRun->error_message,
                    'last_failure_code' => (string) data_get($finalizedRun->metadata, 'last_error_code', ''),
                    'last_failure_run_id' => (string) $finalizedRun->id,
                    'last_failure_at' => now(),
                ])->save();
            }

            return $finalizedRun;
        } catch (\Throwable $exception) {
            if (str_starts_with($exception->getMessage(), 'content_automation_skip:')) {
                $reason = (string) str($exception->getMessage())->after('content_automation_skip:');
                $this->markCompletedIfNeeded($automation, $reason);

                Log::info('content_automation.skipped', [
                    'automation_id' => (string) $automation->id,
                    'reason' => $reason,
                ]);

                return ContentAutomationRun::query()->create([
                    'automation_id' => (string) $automation->id,
                    'organization_id' => (int) $automation->organization_id,
                    'workspace_id' => (string) $automation->workspace_id,
                    'client_site_id' => $automation->client_site_id,
                    'status' => ContentAutomationRunStatus::SKIPPED->value,
                    'triggered_by' => $triggerType->value,
                    'started_at' => now(),
                    'finished_at' => now(),
                    'result_summary' => 'Automation skipped because it became ineligible before run start.',
                    'metadata' => [
                        'skip_reason' => $reason,
                    ],
                ]);
            }

            Log::error('content_automation.run_failed', [
                'automation_id' => (string) $automation->id,
                'automation_name' => (string) $automation->name,
                'run_id' => isset($run) ? (string) $run->id : null,
                'failure_stage' => isset($run) ? 'execution' : 'start',
                'message' => $exception->getMessage(),
                'exception_class' => $exception::class,
                'exception' => $exception,
            ]);

            if (! isset($run)) {
                throw $exception;
            }

            $finalizedRun = $this->finalizeRun(
                $automation,
                $run,
                ContentAutomationRunStatus::FAILED,
                'Automation run failed before article execution completed.',
                [
                    'exception_class' => $exception::class,
                    'failure_stage' => isset($run) ? 'execution' : 'start',
                ],
                [],
                [],
                [],
                $exception->getMessage(),
            );

            $this->failureService->persistFailure($automation, $finalizedRun, $exception, [
                'failure_stage' => isset($run) ? 'execution' : 'start',
                'error_code' => strtolower(class_basename($exception::class)),
                'attempt' => (int) ($finalizedRun->attempt_count ?? 0),
                'workspace_id' => (string) $automation->workspace_id,
                'client_site_id' => (string) ($automation->client_site_id ?? ''),
                'locale' => $automation->sourceLocale(),
                'chain_size' => (int) $automation->chain_size,
            ]);

            return $finalizedRun;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $generatedDraftIds
     * @param  array<int, string>  $generatedContentIds
     * @param  array<int, string>  $publishedContentIds
     */
    private function finalizeRun(
        ContentAutomation $automation,
        ContentAutomationRun $run,
        ContentAutomationRunStatus $status,
        string $summary,
        array $metadata,
        array $generatedDraftIds = [],
        array $generatedContentIds = [],
        array $publishedContentIds = [],
        ?string $errorMessage = null,
    ): ContentAutomationRun {
        $truth = $this->runTruth($run);
        $generatedContentIds = (array) $truth['generated_content_ids'];
        $generatedDraftIds = array_values(array_unique(array_filter(array_merge(
            $generatedDraftIds,
            (array) $truth['generated_draft_ids'],
        ))));
        $publishedContentIds = array_values(array_unique(array_filter($publishedContentIds)));
        $status = $this->statusFromTruth($truth) ?? $status;
        $summary = $this->summaryFromTruth($truth, count($publishedContentIds), $summary);
        $lastError = $this->lastRunError($run);
        $errorMessage ??= $lastError['message'] ?? null;

        $run->forceFill([
            'status' => $status->value,
            'finished_at' => now(),
            'result_summary' => $summary,
            'error_message' => $errorMessage,
            'generated_draft_ids' => array_values(array_unique($generatedDraftIds)),
            'generated_content_ids' => array_values(array_unique($generatedContentIds)),
            'published_content_ids' => array_values(array_unique($publishedContentIds)),
            'metadata' => array_merge(
                is_array($run->metadata) ? $run->metadata : [],
                $metadata,
                [
                    'truth' => $truth,
                    'last_error_code' => $lastError['code'] ?? null,
                    'last_error_message' => $lastError['message'] ?? null,
                    'last_failure_stage' => $lastError['stage'] ?? null,
                    'failure_pattern' => $lastError['pattern'] ?? null,
                    'failure_code' => $lastError['failure_code'] ?? null,
                    'failure_details' => $lastError['details'] ?? null,
                ],
            ),
        ])->save();

        Log::info('content_automation.run_finalized', array_merge($this->logContext($automation, $run), [
            'status' => $status->value,
            'intended_item_count' => (int) $truth['intended_count'],
            'generated_item_count' => (int) $truth['generated_count'],
            'failed_item_count' => (int) $truth['failed_count'],
            'partial_item_count' => (int) $truth['partial_count'],
            'last_error_code' => $lastError['code'] ?? null,
            'last_error_message' => $lastError['message'] ?? null,
        ]));

        if ($status === ContentAutomationRunStatus::COMPLETED || $status === ContentAutomationRunStatus::SKIPPED) {
            $automation->forceFill([
                'last_failure_message' => null,
                'last_failure_code' => null,
                'last_failure_run_id' => null,
                'last_failure_at' => null,
            ])->save();
        }

        $this->markCompletedIfNeeded($automation->fresh() ?? $automation);

        return $run->fresh() ?? $run;
    }

    private function resolveActor(ContentAutomation $automation, ?int $requestedByUserId = null): User
    {
        if ($requestedByUserId !== null) {
            $requested = User::query()->find($requestedByUserId);
            if ($requested) {
                return $requested;
            }
        }

        foreach ([$automation->updated_by, $automation->created_by] as $candidateId) {
            if (! $candidateId) {
                continue;
            }

            $candidate = User::query()->find($candidateId);
            if ($candidate) {
                return $candidate;
            }
        }

        $site = $automation->clientSite ?: ClientSite::query()->where('workspace_id', $automation->workspace_id)->first();
        $organizationId = $automation->organization_id ?: $site?->workspace?->organization_id;

        return User::query()
            ->where('organization_id', $organizationId)
            ->orderByRaw("case when role = 'owner' then 0 when role = 'admin' then 1 else 2 end")
            ->orderBy('created_at')
            ->firstOrFail();
    }

    /**
     * @return array{0:ContentAutomation,1:ContentAutomationRun,2:bool}
     */
    private function startRun(
        ContentAutomation $automation,
        ContentAutomationTriggerType $triggerType,
        ?int $requestedByUserId,
    ): array {
        return DB::transaction(function () use ($automation, $triggerType, $requestedByUserId): array {
            /** @var ContentAutomation $locked */
            $locked = ContentAutomation::query()
                ->whereKey($automation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $skipReason = $locked->skipReason();
            if ($skipReason !== null) {
                throw new \RuntimeException('content_automation_skip:' . $skipReason);
            }

            $resumableRun = ContentAutomationRun::query()
                ->where('automation_id', (string) $locked->id)
                ->whereIn('status', [
                    ContentAutomationRunStatus::RUNNING->value,
                    ContentAutomationRunStatus::PARTIAL->value,
                    ContentAutomationRunStatus::FAILED->value,
                ])
                ->latest('created_at')
                ->first();

            if ($resumableRun instanceof ContentAutomationRun && $this->runHasRemainingWork($resumableRun)) {
                $resumableRun->forceFill([
                    'status' => ContentAutomationRunStatus::RUNNING->value,
                    'last_attempt_at' => now(),
                    'attempt_count' => max(1, (int) $resumableRun->attempt_count + 1),
                    'metadata' => array_merge(is_array($resumableRun->metadata) ? $resumableRun->metadata : [], [
                        'resumed_at' => now()->toIso8601String(),
                        'triggered_by_user_id' => $requestedByUserId,
                    ]),
                ])->save();

                return [$locked->fresh(['latestRun']) ?? $locked, $resumableRun->fresh(['items']) ?? $resumableRun, true];
            }

            $run = ContentAutomationRun::query()->create([
                'automation_id' => (string) $locked->id,
                'organization_id' => (int) $locked->organization_id,
                'workspace_id' => (string) $locked->workspace_id,
                'client_site_id' => $locked->client_site_id,
                'status' => ContentAutomationRunStatus::RUNNING->value,
                'triggered_by' => $triggerType->value,
                'attempt_count' => 1,
                'last_attempt_at' => now(),
                'started_at' => now(),
                'generated_draft_ids' => [],
                'generated_content_ids' => [],
                'published_content_ids' => [],
                'metadata' => [
                    'triggered_by_user_id' => $requestedByUserId,
                    'run_count_increment_policy' => 'increment_on_started_run',
                ],
            ]);

            $locked->forceFill([
                'run_count' => (int) $locked->run_count + 1,
                'last_run_at' => CarbonImmutable::now(),
                'next_run_at' => $locked->calculateNextRunAt(),
            ])->save();

            return [$locked->fresh(['latestRun']) ?? $locked, $run, false];
        });
    }

    private function summaryForRun(int $successCount, int $failureCount, int $publishedCount, int $partialCount = 0): string
    {
        $summary = sprintf('%d article(s) generated', $successCount);

        if ($publishedCount > 0) {
            $summary .= sprintf(', %d queued for publish', $publishedCount);
        }

        if ($failureCount > 0) {
            $summary .= sprintf(', %d failed', $failureCount);
        }

        if ($partialCount > 0) {
            $summary .= sprintf(', %d partial', $partialCount);
        }

        return $summary . '.';
    }

    private function summaryForStoppedRun(int $successCount, string $reason): string
    {
        $label = match ($reason) {
            'paused' => 'paused',
            'end_at_reached' => 'end date reached',
            'max_runs_reached' => 'max runs reached',
            default => 'stopped',
        };

        return sprintf('Automation stopped after %d article(s): %s.', $successCount, $label);
    }

    private function markCompletedIfNeeded(ContentAutomation $automation, ?string $reason = null): void
    {
        $reason ??= $automation->completionReason();

        if (! in_array($reason, ['end_at_reached', 'max_runs_reached'], true)) {
            return;
        }

        $automation->forceFill([
            'is_paused' => true,
            'paused_at' => $automation->paused_at ?: CarbonImmutable::now(),
        ])->save();

        Log::info('content_automation.completed', [
            'automation_id' => (string) $automation->id,
            'reason' => $reason,
        ]);
    }

    private function stopReasonForCurrentRun(ContentAutomation $automation): ?string
    {
        if ($automation->isPaused()) {
            return 'paused';
        }

        if ($automation->end_at && $automation->end_at->lt(now())) {
            return 'end_at_reached';
        }

        return null;
    }

    private function markItemSkipped(ContentAutomation $automation, ContentAutomationRun $run, ContentAutomationRunItem $item, string $reason): void
    {
        $item->forceFill([
            'status' => ContentAutomationRunItem::STATUS_SKIPPED,
            'failure_stage' => 'lifecycle',
            'last_error_code' => $reason,
            'last_error_message' => 'Automation stopped before this item could run: '.$reason,
            'finished_at' => now(),
        ])->save();

        Log::info('content_automation.item_failed', array_merge($this->itemLogContext($automation, $run, $item), [
            'failure_stage' => 'lifecycle',
            'last_error_code' => $reason,
            'exception_message' => $item->last_error_message,
        ]));
    }

    private function markItemFailed(
        ContentAutomation $automation,
        ContentAutomationRun $run,
        ContentAutomationRunItem $item,
        array $articlePlan,
        \Throwable $exception,
        string $stage,
        ?string $code = null,
    ): void {
        $item = $item->fresh() ?? $item;

        $item->forceFill([
            'status' => ContentAutomationRunItem::STATUS_FAILED,
            'failure_stage' => $stage,
            'last_error_code' => $code ?: Str::snake(class_basename($exception::class)),
            'last_error_message' => $exception->getMessage(),
            'finished_at' => now(),
            'metadata' => array_merge(is_array($item->metadata) ? $item->metadata : [], [
                'exception_class' => $exception::class,
                'plan' => $articlePlan,
            ]),
        ])->save();

        Log::error('content_automation.item_failed', array_merge($this->itemLogContext($automation, $run, $item, $articlePlan), [
            'failure_stage' => $stage,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]));
    }

    /**
     * @return array<string,mixed>
     */
    public function runTruth(ContentAutomationRun $run): array
    {
        $run->load('items');
        $contentIds = Content::query()
            ->where('automation_run_id', (string) $run->id)
            ->pluck('id')
            ->merge(
                $run->items
                    ->pluck('content_id')
                    ->filter()
                    ->map(fn ($id): string => (string) $id)
            )
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
        $draftIds = ContentAutomationRunItem::query()
            ->where('automation_run_id', (string) $run->id)
            ->whereNotNull('draft_id')
            ->pluck('draft_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $items = $run->items;

        return [
            'intended_count' => $items->count(),
            'generated_count' => count($contentIds),
            'failed_count' => $items->where('status', ContentAutomationRunItem::STATUS_FAILED)->count(),
            'partial_count' => $items->where('status', ContentAutomationRunItem::STATUS_PARTIAL)->count(),
            'skipped_count' => $items->where('status', ContentAutomationRunItem::STATUS_SKIPPED)->count(),
            'completed_count' => $items->where('status', ContentAutomationRunItem::STATUS_COMPLETED)->count(),
            'running_count' => $items->where('status', ContentAutomationRunItem::STATUS_RUNNING)->count(),
            'planned_count' => $items->where('status', ContentAutomationRunItem::STATUS_PLANNED)->count(),
            'pending_count' => $items->whereIn('status', [
                ContentAutomationRunItem::STATUS_PLANNED,
                ContentAutomationRunItem::STATUS_RUNNING,
            ])->count(),
            'generated_content_ids' => $contentIds,
            'generated_draft_ids' => $draftIds,
        ];
    }

    private function statusFromTruth(array $truth, ?string $stopReason = null): ?ContentAutomationRunStatus
    {
        $intended = (int) ($truth['intended_count'] ?? 0);
        $generated = (int) ($truth['generated_count'] ?? 0);
        $completed = (int) ($truth['completed_count'] ?? 0);
        $failed = (int) ($truth['failed_count'] ?? 0);
        $partial = (int) ($truth['partial_count'] ?? 0);
        $skipped = (int) ($truth['skipped_count'] ?? 0);
        $pending = (int) (($truth['running_count'] ?? 0) + ($truth['planned_count'] ?? 0));

        if ($intended === 0) {
            return null;
        }

        return match (true) {
            $generated === 0 && $failed === 0 && $skipped > 0 && $stopReason !== null => ContentAutomationRunStatus::SKIPPED,
            $failed === $intended => ContentAutomationRunStatus::FAILED,
            $completed === $intended => ContentAutomationRunStatus::COMPLETED,
            $generated === 0 && $failed > 0 => ContentAutomationRunStatus::FAILED,
            $generated === 0 && $partial > 0 => ContentAutomationRunStatus::PARTIAL,
            $generated > 0 && ($failed > 0 || $partial > 0 || $skipped > 0 || $pending > 0) => ContentAutomationRunStatus::PARTIAL,
            $generated > 0 && $completed < $intended => ContentAutomationRunStatus::PARTIAL,
            $generated > 0 => ContentAutomationRunStatus::COMPLETED,
            default => ContentAutomationRunStatus::FAILED,
        };
    }

    /**
     * @param  array<string, mixed>  $truth
     */
    private function summaryFromTruth(array $truth, int $publishedCount, string $fallback): string
    {
        $completed = (int) ($truth['completed_count'] ?? 0);
        $failed = (int) ($truth['failed_count'] ?? 0);
        $partial = (int) ($truth['partial_count'] ?? 0);
        $pending = (int) ($truth['pending_count'] ?? 0);

        if ($completed === 0 && $failed === 0 && $partial === 0 && $pending === 0) {
            return $fallback;
        }

        if ($failed === 0 && $partial === 0 && $pending === 0) {
            return $fallback;
        }

        $summary = sprintf('%d locale item(s) completed', $completed);

        if ($pending + $partial > 0) {
            $summary .= sprintf(', %d pending', $pending + $partial);
        }

        if ($publishedCount > 0) {
            $summary .= sprintf(', %d published', $publishedCount);
        }

        if ($failed > 0) {
            $summary .= sprintf(', %d failed', $failed);
        }

        return $summary . '.';
    }

    /**
     * @return array{code:?string,message:?string,stage:?string,pattern:?string,failure_code:?string,details:?array}
     */
    private function lastRunError(ContentAutomationRun $run): array
    {
        $item = ContentAutomationRunItem::query()
            ->where('automation_run_id', (string) $run->id)
            ->whereNotNull('last_error_message')
            ->orderByDesc('updated_at')
            ->first();

        return [
            'code' => $item?->last_error_code,
            'message' => data_get($item?->metadata, 'failure_details.user_safe_message', $item?->last_error_message),
            'stage' => $item?->failure_stage,
            'pattern' => data_get($item?->metadata, 'failure_pattern'),
            'failure_code' => data_get($item?->metadata, 'failure_code'),
            'details' => data_get($item?->metadata, 'failure_details'),
        ];
    }

    private function failureStageFromException(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'site') => 'site_resolution',
            str_contains($message, 'prompt') => 'prompt',
            str_contains($message, 'draft') || str_contains($message, 'generation') => 'generation',
            str_contains($message, 'publish') || str_contains($message, 'publication') => 'publish',
            str_contains($message, 'save') || str_contains($message, 'sql') || str_contains($message, 'database') => 'persistence',
            default => 'generation',
        };
    }

    private function runHasRemainingWork(ContentAutomationRun $run): bool
    {
        $run->loadMissing('items');

        if ($run->items->isEmpty()) {
            return true;
        }

        return $run->items->contains(function (ContentAutomationRunItem $item): bool {
            return in_array((string) $item->status, [
                ContentAutomationRunItem::STATUS_PLANNED,
                ContentAutomationRunItem::STATUS_RUNNING,
                ContentAutomationRunItem::STATUS_FAILED,
                ContentAutomationRunItem::STATUS_PARTIAL,
            ], true);
        });
    }

    /**
     * @param  array<string, mixed>  $articlePlan
     * @return array<string, mixed>|null
     */
    private function resumeExistingItemResult(
        ContentAutomation $automation,
        ContentAutomationRun $run,
        ContentAutomationRunItem $item,
        array $articlePlan
    ): ?array {
        if (! in_array((string) $item->status, [
            ContentAutomationRunItem::STATUS_COMPLETED,
            ContentAutomationRunItem::STATUS_PARTIAL,
        ], true)) {
            return null;
        }

        $contentId = $this->existingContentId((string) ($item->content_id ?? ''));
        if ($contentId === null) {
            return null;
        }

        $content = Content::query()->find($contentId);
        if (! $content instanceof Content) {
            return null;
        }

        $this->itemStateService->syncFromContent($content);
        $item = $item->fresh() ?? $item;

        Log::info('content_automation.item_reused', array_merge(
            $this->itemLogContext($automation, $run, $item, $articlePlan),
            [
                'reused_content_id' => (string) $content->id,
                'existing_status' => (string) $item->status,
            ],
        ));

        return [
            'content_id' => (string) $content->id,
            'brief_id' => $this->existingBriefId((string) ($item->brief_id ?? '')),
            'draft_id' => $this->existingDraftId((string) ($item->draft_id ?? '')),
            'published_content_ids' => in_array((string) ($content->publish_status ?? $content->status), ['published'], true)
                ? [(string) $content->id]
                : [],
            'item_status' => (string) $item->status,
            'last_error_code' => (string) ($item->last_error_code ?? ''),
            'last_error_message' => (string) ($item->last_error_message ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function logContext(ContentAutomation $automation, ?ContentAutomationRun $run = null): array
    {
        return [
            'automation_id' => (string) $automation->id,
            'automation_name' => (string) $automation->name,
            'run_id' => $run ? (string) $run->id : null,
            'site_id' => (string) ($automation->client_site_id ?? ''),
            'locale' => $automation->sourceLocale(),
        ];
    }

    /**
     * @param array<string,mixed> $articlePlan
     * @return array<string,mixed>
     */
    private function itemLogContext(ContentAutomation $automation, ContentAutomationRun $run, ?ContentAutomationRunItem $item = null, array $articlePlan = []): array
    {
        return array_merge($this->logContext($automation, $run), [
            'item_id' => $item ? (string) $item->id : null,
            'chain_index' => (int) ($item?->chain_index ?? ($articlePlan['sequence'] ?? 0)),
            'locale' => (string) ($item?->locale ?? ($articlePlan['target_locale'] ?? $automation->sourceLocale())),
            'provider' => $item?->provider,
            'model' => $item?->model,
            'prompt_hash' => $item?->prompt_hash,
            'prompt_present' => $item?->prompt_hash !== null,
            'created_content_id' => $item?->content_id,
        ]);
    }

    private function existingContentId(string $contentId): ?string
    {
        return $contentId !== '' && Content::query()->whereKey($contentId)->exists() ? $contentId : null;
    }

    private function existingDraftId(string $draftId): ?string
    {
        return $draftId !== '' && Draft::query()->whereKey($draftId)->exists() ? $draftId : null;
    }

    private function existingBriefId(string $briefId): ?string
    {
        return $briefId !== '' && Brief::query()->whereKey($briefId)->exists() ? $briefId : null;
    }
}
