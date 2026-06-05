<?php

namespace App\Services\AgenticMarketing;

use App\Actions\Content\CreateRefreshDraft;
use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\Support\AgentRunStatus;
use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentOriginType;
use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingRun;
use App\Models\AgenticMarketingRunItem;
use App\Models\AgentRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AgenticMarketingActionExecutor
{
    public function __construct(
        private readonly CreateRefreshDraft $createRefreshDraft,
        private readonly AgenticMarketingBudgetGuard $budgetGuard,
        private readonly AgenticMarketingApprovalPolicyEngine $approvalPolicyEngine,
        private readonly AgenticActionRunLogger $actionRunLogger,
    ) {
    }

    public function execute(AgenticMarketingAction $action, ?User $actor = null, ?string $claimId = null): AgenticMarketingAction
    {
        $actionId = (string) $action->id;
        $action = $this->claimOrResolveClaimedAction($action, $claimId);
        if (! $action) {
            return AgenticMarketingAction::query()->findOrFail($actionId);
        }

        Log::info('agentic_marketing.action.execution_started', $this->logContext($action));
        $this->actionRunLogger->markRunning($action, $claimId);
        $run = $this->startExecutionRun($action);
        $item = AgenticMarketingRunItem::query()->create([
            'run_id' => $run->id,
            'objective_id' => $action->objective_id,
            'opportunity_id' => $action->opportunity_id,
            'action_id' => $action->id,
            'type' => AgenticMarketingRunItem::TYPE_EXECUTION,
            'name' => 'Execute ' . str_replace('_', ' ', (string) $action->action_type),
            'status' => AgenticMarketingRunItem::STATUS_QUEUED,
            'payload' => [
                'action_type' => (string) $action->action_type,
                'claim_id' => $claimId,
            ],
        ]);
        $item->markRunning();
        $reservation = null;

        try {
            $this->assertActionTenantSafety($action);
            $decision = $this->approvalPolicyEngine->decide($action);
            if ($this->shouldReserveCredits($action, $decision)) {
                $reservation = $this->budgetGuard->reserveBeforeExecution($action, $actor, $claimId);
            } else {
                $action->forceFill([
                    'credit_status' => 'skipped',
                    'credits_reserved' => null,
                    'credits_captured' => null,
                    'credit_reservation_id' => null,
                    'credit_error_message' => null,
                    'budget_checked_at' => now(),
                    'budget_exceeded_at' => null,
                ])->save();
            }

            $handler = match ($action->action_type) {
                'refresh_article' => 'refreshArticle',
                'add_answer_block' => 'addAnswerBlock',
                'improve_internal_links' => 'improveInternalLinks',
                'create_locale_variant' => 'createLocaleVariant',
                'update_meta' => 'updateMeta',
                'add_schema' => 'addSchema',
                'create_article' => 'createArticle',
                default => null,
            };

            if (! $handler) {
                throw new RuntimeException('Unsupported Agentic Marketing action type.');
            }

            $result = $this->normalizeResult(array_replace_recursive(
                $this->{$handler}($action, $actor, $decision),
                ['autonomy' => $decision->toArray()],
            ));
            $this->budgetGuard->captureAfterExecution($action, $reservation, $actor);

            $action->forceFill([
                'status' => AgenticMarketingAction::STATUS_COMPLETED,
                'result' => $result,
                'run_id' => (string) $run->id,
                'draft_id' => $result['created_draft_id'] ?? $action->draft_id,
                'content_id' => $result['created_content_id'] ?? $action->content_id,
                'completed_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            Log::info('agentic_marketing.action.execution_completed', $this->logContext($action));
            $this->actionRunLogger->markCompleted($action->loadMissing(['objective', 'opportunity']));
            $item->markCompleted($result);
            $run->markCompleted([
                'action_id' => (string) $action->id,
                'result' => $result,
            ]);
            app(AgenticMarketingAuditLogger::class)->record($action->loadMissing(['objective', 'opportunity', 'run']), 'action.executed', null, $result);
        } catch (Throwable $exception) {
            $this->budgetGuard->releaseAfterFailure($action, $reservation, $this->friendlyMessage($exception), $actor);
            $this->failAction($action, $this->friendlyMessage($exception), $exception);
            $this->actionRunLogger->markFailed($action->loadMissing(['objective', 'opportunity']), $this->friendlyMessage($exception));
            $item->markFailed($this->friendlyMessage($exception));
            $run->markFailed($this->friendlyMessage($exception));
            app(AgenticMarketingAuditLogger::class)->record($action->loadMissing(['objective', 'opportunity', 'run']), 'action.execution_failed', null, [
                'error_message' => $this->friendlyMessage($exception),
            ]);
        }

        return $action->fresh(['objective', 'opportunity', 'content', 'draft']);
    }

    private function startExecutionRun(AgenticMarketingAction $action): AgenticMarketingRun
    {
        $run = AgenticMarketingRun::query()->create([
            'objective_id' => $action->objective_id,
            'status' => AgenticMarketingRun::STATUS_QUEUED,
            'payload' => [
                'type' => 'action_execution',
                'action_id' => (string) $action->id,
                'action_type' => (string) $action->action_type,
            ],
        ]);

        $run->markRunning();
        $action->forceFill(['run_id' => (string) $run->id])->save();
        app(AgenticMarketingAuditLogger::class)->record($run->loadMissing('objective'), 'run.started', null, $run->attributesToArray());

        return $run;
    }

    private function shouldReserveCredits(AgenticMarketingAction $action, AgenticMarketingAutonomyDecision $decision): bool
    {
        if ($decision->dryRun) {
            return false;
        }

        if ($decision->mayAutoApply || $decision->mayCreateDraft) {
            return true;
        }

        return in_array((string) $action->action_type, [
            'create_article',
            'refresh_article',
        ], true);
    }

    private function claimOrResolveClaimedAction(AgenticMarketingAction $action, ?string $claimId = null): ?AgenticMarketingAction
    {
        return DB::transaction(function () use ($action, $claimId): ?AgenticMarketingAction {
            $locked = AgenticMarketingAction::query()
                ->with(['objective', 'opportunity', 'content.drafts', 'content.currentRevision', 'content.currentVersion'])
                ->lockForUpdate()
                ->findOrFail($action->id);

            if ($locked->status === AgenticMarketingAction::STATUS_APPROVED) {
                $locked->forceFill([
                    'status' => AgenticMarketingAction::STATUS_RUNNING,
                    'execution_claim_id' => $claimId ?: (string) Str::uuid(),
                    'execution_claimed_at' => now(),
                    'started_at' => now(),
                    'completed_at' => null,
                    'failed_at' => null,
                    'error_message' => null,
                ])->save();

                return $locked;
            }

            if (
                $locked->status === AgenticMarketingAction::STATUS_RUNNING
                && $claimId
                && $locked->execution_claim_id === $claimId
                && ! $locked->started_at
            ) {
                $locked->forceFill([
                    'started_at' => now(),
                    'completed_at' => null,
                    'failed_at' => null,
                    'error_message' => null,
                ])->save();

                return $locked;
            }

            Log::info('agentic_marketing.action.execution_skipped', $this->logContext($locked));

            return null;
        });
    }

    private function refreshArticle(AgenticMarketingAction $action, ?User $actor, AgenticMarketingAutonomyDecision $decision): array
    {
        $content = $this->resolveContent($action);

        if ($decision->dryRun) {
            return $this->dryRunResult($action, 'Refresh draft would be prepared if policy allowed execution.');
        }

        if (! $content) {
            return $this->proposalResult(
                'Refresh recommendation prepared. No content item was attached, so no draft was created.',
                suggestions: [$this->recommendation($action, 'Create a supervised refresh draft after selecting the source content.')],
                serviceUsed: 'safe_recommendation'
            );
        }

        if (! $decision->mayCreateDraft) {
            return $this->proposalResult(
                'Refresh recommendation prepared. Manual approval is required before creating a supervised draft.',
                suggestions: [$this->recommendation($action, 'Approve draft creation after reviewing the refresh scope.')],
                serviceUsed: 'policy_proposal_only'
            );
        }

        if ($content->client_site_id && $actor) {
            $run = $this->createRefreshAgentRun($action, $content, $actor);
            $draft = $this->createRefreshDraft->execute($content, $run, $actor);

            return [
                'summary' => 'Refresh draft created for editorial review. Published content was not changed.',
                'created_content_id' => (string) $content->id,
                'created_draft_id' => (string) $draft->id,
                'suggestions' => [$this->recommendation($action, 'Review the refresh draft before publishing changes.')],
                'warnings' => [],
                'service_used' => CreateRefreshDraft::class,
            ];
        }

        if (! $content->client_site_id) {
            return $this->proposalResult(
                'Refresh recommendation prepared. This content has no connected site, so no draft was created.',
                suggestions: [$this->recommendation($action, 'Attach the content to a site before creating a supervised refresh draft.')],
                warnings: ['Draft creation skipped because drafts require a site context.'],
                serviceUsed: 'safe_refresh_recommendation'
            );
        }

        $draft = $this->createReviewDraft($content, $action, 'agentic_refresh', $actor);

        return [
            'summary' => 'Safe refresh draft created for review. Published content was not overwritten.',
            'created_content_id' => (string) $content->id,
            'created_draft_id' => (string) $draft->id,
            'suggestions' => [$this->recommendation($action, 'Connect the full content refresh agent when source content has a site and actor context.')],
            'warnings' => ['Used supervised draft fallback because the full refresh service needs a site and actor context.'],
            'service_used' => 'safe_refresh_draft_fallback',
        ];
    }

    private function addAnswerBlock(AgenticMarketingAction $action, ?User $actor, AgenticMarketingAutonomyDecision $decision): array
    {
        return $this->proposalResult(
            $decision->dryRun
                ? 'Dry run: answer block proposal would be generated for review. No live content was changed.'
                : 'Answer block proposal prepared for review. No live content was changed.',
            suggestions: [[
                'type' => 'answer_block',
                'question' => $this->payloadText($action, 'question', 'What should buyers know about this topic?'),
                'answer' => $this->recommendation($action, 'Add a concise answer-first block that improves AI answer extraction.')['text'],
                'review_required' => true,
            ]],
            serviceUsed: 'safe_answer_block_proposal'
        );
    }

    private function improveInternalLinks(AgenticMarketingAction $action, ?User $actor, AgenticMarketingAutonomyDecision $decision): array
    {
        return $this->proposalResult(
            $decision->dryRun
                ? 'Dry run: internal link suggestions would be generated for approval. Direct insertion remains gated.'
                : 'Internal link opportunities prepared as suggestions. Direct insertion was skipped for review safety.',
            suggestions: [[
                'type' => 'internal_link_suggestion',
                'source_content_id' => $this->payloadText($action, 'content_id', $action->content_id),
                'target' => $this->payloadText($action, 'target', 'Recommended related content'),
                'anchor_text' => $this->payloadText($action, 'anchor_text', 'contextual anchor'),
                'reason' => $this->payloadText($action, 'reason', 'Improve topical authority and crawl paths.'),
                'review_required' => true,
            ]],
            serviceUsed: 'safe_internal_link_suggestions'
        );
    }

    private function createLocaleVariant(AgenticMarketingAction $action, ?User $actor, AgenticMarketingAutonomyDecision $decision): array
    {
        if ($decision->dryRun) {
            return $this->dryRunResult($action, 'Locale variant request would be prepared for review.');
        }

        $content = $this->resolveContent($action);
        if (! $content) {
            throw new RuntimeException('Select a source content item before creating a locale variant.');
        }

        $targetLocale = SupportedLanguage::fromStringOrDefault(
            $this->payloadText($action, 'target_locale', $this->payloadText($action, 'locale', 'nl'))
        )->value;

        if ($content->activeTranslationRequestForLocale($targetLocale)) {
            throw new RuntimeException('A translation for this locale is already queued or processing.');
        }

        $existing = $content->translationRequestForLocale($targetLocale);
        if ($existing && $existing->target_content_id) {
            return [
                'summary' => 'Locale variant already exists. No duplicate record was created.',
                'created_content_id' => (string) $existing->target_content_id,
                'created_draft_id' => null,
                'suggestions' => [$this->recommendation($action, 'Review the existing locale variant before starting another localization workflow.')],
                'warnings' => ['Existing locale variant detected.'],
                'service_used' => 'translation_lock_guard',
            ];
        }

        return $this->proposalResult(
            sprintf('Locale variant proposal prepared for %s. Translation was not queued automatically.', strtoupper($targetLocale)),
            suggestions: [[
                'type' => 'locale_variant_request',
                'source_content_id' => (string) $content->id,
                'target_locale' => $targetLocale,
                'reason' => $this->payloadText($action, 'reason', 'Expand AI visibility in the target locale.'),
                'review_required' => true,
            ]],
            serviceUsed: 'translation_lock_guard'
        );
    }

    private function updateMeta(AgenticMarketingAction $action, ?User $actor, AgenticMarketingAutonomyDecision $decision): array
    {
        $content = $this->resolveContent($action);
        $title = $this->payloadText($action, 'seo_title', $content?->seo_title ?: $content?->title ?: 'AI visibility guide');
        $description = $this->payloadText($action, 'seo_meta_description', $this->payloadText($action, 'recommendation', 'Clarify the page promise for AI search and human buyers.'));
        $proposed = [
            'seo_title' => Str::limit($title, 68, ''),
            'seo_meta_description' => Str::limit($description, 158, ''),
        ];

        if ($decision->dryRun) {
            return $this->dryRunResult($action, 'Metadata would be updated with rollback metadata.', ['proposed_changes' => $proposed]);
        }

        if ($decision->mayAutoApply && $content) {
            $rollback = $this->rollbackMetadata($content, array_keys($proposed));
            $content->forceFill($proposed)->save();

            return [
                'summary' => 'Low-risk SEO metadata updated with rollback metadata. Published content body was not changed.',
                'created_content_id' => (string) $content->id,
                'created_draft_id' => null,
                'suggestions' => [$this->recommendation($action, 'Review applied metadata and roll back from stored metadata if needed.')],
                'warnings' => [],
                'service_used' => 'policy_auto_metadata_apply',
                'applied_changes' => $proposed,
                'rollback' => $rollback,
            ];
        }

        return $this->proposalResult(
            'SEO metadata proposal prepared. Existing metadata was not overwritten.',
            suggestions: [[
                'type' => 'metadata',
                'seo_title' => $proposed['seo_title'],
                'seo_meta_description' => $proposed['seo_meta_description'],
                'review_required' => true,
            ]],
            serviceUsed: 'safe_meta_proposal'
        );
    }

    private function addSchema(AgenticMarketingAction $action, ?User $actor, AgenticMarketingAutonomyDecision $decision): array
    {
        $content = $this->resolveContent($action);
        $schemaType = $this->payloadText($action, 'schema_type', $content?->schema_type ?: 'Article');
        $proposed = ['schema_type' => $schemaType];

        if ($decision->dryRun) {
            return $this->dryRunResult($action, 'Schema metadata would be updated with rollback metadata.', ['proposed_changes' => $proposed]);
        }

        if ($decision->mayAutoApply && $content) {
            $rollback = $this->rollbackMetadata($content, array_keys($proposed));
            $content->forceFill($proposed)->save();

            return [
                'summary' => 'Low-risk schema metadata updated with rollback metadata. Existing schema rendering was not published automatically.',
                'created_content_id' => (string) $content->id,
                'created_draft_id' => null,
                'suggestions' => [$this->recommendation($action, 'Review applied schema metadata and roll back if needed.')],
                'warnings' => [],
                'service_used' => 'policy_auto_schema_apply',
                'applied_changes' => $proposed,
                'rollback' => $rollback,
            ];
        }

        return $this->proposalResult(
            'Structured data proposal prepared for review. Existing schema rendering was not changed.',
            suggestions: [[
                'type' => 'schema',
                'schema' => [
                    '@type' => $schemaType,
                    'headline' => $content?->title ?: $this->payloadText($action, 'headline', 'Agentic marketing content'),
                    'inLanguage' => $this->payloadText($action, 'locale', $content?->localeCode() ?: 'en'),
                ],
                'review_required' => true,
            ]],
            serviceUsed: 'safe_schema_proposal'
        );
    }

    private function createArticle(AgenticMarketingAction $action, ?User $actor, AgenticMarketingAutonomyDecision $decision): array
    {
        if ($decision->dryRun) {
            return $this->dryRunResult($action, 'Draft content item would be created for review. Auto-publish remains disabled.');
        }

        $objective = $action->objective;
        $workspaceId = $objective?->workspace_id ?: $this->payloadText($action, 'workspace_id');
        if (! $workspaceId) {
            throw new RuntimeException('Select a workspace before creating an article draft.');
        }

        return DB::transaction(function () use ($action, $actor, $workspaceId): array {
            $language = SupportedLanguage::fromStringOrDefault($this->payloadText($action, 'locale', $action->objective?->locale ?: 'en'))->value;
            $title = $this->payloadText($action, 'title', $action->opportunity?->title ?: $action->objective?->goal ?: 'Agentic marketing opportunity');
            $siteId = $this->articleClientSiteId($action, (string) $workspaceId);
            $summary = $this->payloadText($action, 'reason', $this->payloadText($action, 'recommendation', 'Draft created from an Agentic Marketing opportunity for editorial review.'));

            $content = Content::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => (string) $workspaceId,
                'client_site_id' => $siteId,
                'title' => $title,
                'primary_keyword' => $this->payloadText($action, 'primary_keyword') ?: null,
                'type' => ContentType::ARTICLE->value,
                'status' => 'draft',
                'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
                'source' => ContentSource::AUTOMATION->value,
                'origin_type' => ContentOriginType::AUTOMATION->value,
                'delivery_status' => 'pending',
                'generation_mode' => 'balanced',
                'language' => $language,
                'auto_publish' => false,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $draft = null;
            if ($content->client_site_id) {
                $brief = $this->ensureBrief($content, $actor, $title, $action);
                $draft = Draft::query()->create([
                    'id' => (string) Str::uuid(),
                    'brief_id' => (string) $brief->id,
                    'content_id' => (string) $content->id,
                    'client_site_id' => $content->client_site_id,
                    'status' => 'generated',
                    'title' => $title,
                    'seo_title' => Str::limit($title, 68, ''),
                    'seo_meta_description' => Str::limit($summary, 158, ''),
                    'seo_h1' => $title,
                    'schema_type' => $this->payloadText($action, 'suggested_schema', 'Article'),
                    'output_type' => 'kb_article',
                    'language' => $language,
                    'draft_type' => DraftType::ORIGINAL->value,
                    'content_html' => $this->articleDraftHtml($action, $title, $summary),
                    'delivery_status' => 'pending',
                    'meta' => [
                        'source' => 'agentic_marketing',
                        'agentic_marketing_action_id' => (string) $action->id,
                        'agentic_marketing_opportunity_id' => (string) $action->opportunity_id,
                        'briefing_complete' => true,
                        'review_required' => true,
                        'auto_publish' => false,
                    ],
                ]);
            }

            return [
                'summary' => $draft
                    ? 'Article draft created. It is not published and requires editorial review.'
                    : 'Draft content item created. No publishing occurred; attach a site before creating an editable draft record.',
                'created_content_id' => (string) $content->id,
                'created_draft_id' => $draft ? (string) $draft->id : null,
                'suggestions' => [$this->recommendation($action, 'Complete the draft, review metadata, and approve manually before publishing.')],
                'warnings' => $draft ? [] : ['Draft record skipped because no client_site_id was provided.'],
                'service_used' => 'safe_article_draft_creator',
            ];
        });
    }

    private function createRefreshAgentRun(AgenticMarketingAction $action, Content $content, User $actor): AgentRun
    {
        return AgentRun::query()->create([
            'id' => (string) Str::uuid(),
            'agent_key' => ContentRefreshAgent::KEY,
            'trigger_type' => 'manual',
            'trigger_source' => 'app.agentic-marketing.actions.execute',
            'status' => AgentRunStatus::SUCCESS->value,
            'organization_id' => $action->objective?->organization_id,
            'workspace_id' => $content->workspace_id,
            'site_id' => $content->client_site_id,
            'content_id' => (string) $content->id,
            'user_id' => $actor->id,
            'input_payload' => $action->payload ?? [],
            'output_payload' => [
                'summary' => $this->payloadText($action, 'recommendation', 'Agentic Marketing refresh recommendation.'),
                'raw_payload' => [
                    'refresh_score' => 75,
                    'urgency_level' => 'medium',
                    'reasons' => [
                        ['title' => 'Agentic Marketing recommendation', 'description' => $this->payloadText($action, 'reason', 'Refresh requested by approved action.')],
                    ],
                    'suggested_actions' => [
                        $this->recommendation($action, 'Create a supervised refresh draft.'),
                    ],
                ],
            ],
            'summary' => 'Agentic Marketing refresh recommendation.',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    private function createReviewDraft(Content $content, AgenticMarketingAction $action, string $source, ?User $actor = null): Draft
    {
        $latestDraft = $content->drafts()->latest('created_at')->first();
        $brief = $latestDraft?->brief_id ? null : $this->ensureBrief($content, $actor, (string) ($content->title ?: 'Untitled content'), $action);

        return Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => $latestDraft?->brief_id ?: (string) $brief?->id,
            'content_id' => (string) $content->id,
            'client_site_id' => $content->client_site_id,
            'content_destination_id' => $content->content_destination_id,
            'status' => 'generated',
            'title' => (string) ($content->title ?: 'Untitled content'),
            'seo_title' => $content->seo_title ?: $content->title,
            'seo_meta_description' => $content->seo_meta_description,
            'seo_h1' => $content->seo_h1 ?: $content->title,
            'seo_canonical' => $content->seo_canonical,
            'schema_type' => $content->schema_type,
            'output_type' => $latestDraft?->output_type ?: 'kb_article',
            'language' => $content->localeCode(),
            'draft_type' => $latestDraft?->draft_type?->value ?: DraftType::ORIGINAL->value,
            'source_draft_id' => $latestDraft?->source_draft_id,
            'translation_source_language' => $latestDraft?->translation_source_language,
            'content_html' => $latestDraft?->content_html ?: '<p>Review and expand this Agentic Marketing refresh draft before publishing.</p>',
            'delivery_status' => 'pending',
            'meta' => [
                'source' => $source,
                'agentic_marketing_action_id' => (string) $action->id,
                'recommendation' => $this->payloadText($action, 'recommendation'),
                'review_required' => true,
            ],
        ]);
    }

    private function ensureBrief(Content $content, ?User $actor, string $title, ?AgenticMarketingAction $action = null): Brief
    {
        if ($content->brief) {
            return $content->brief;
        }

        if (! $content->client_site_id) {
            throw new RuntimeException('A connected site is required before creating an editable draft.');
        }

        return Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $content->client_site_id,
            'content_destination_id' => $content->content_destination_id,
            'created_by_user_id' => $actor?->id,
            'content_id' => (string) $content->id,
            'status' => 'draft',
            'source' => 'agentic_marketing',
            'progress' => 0,
            'title' => $title,
            'language' => $content->localeCode(),
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'primary_keyword' => $content->primary_keyword,
            'intent' => $action ? $this->payloadText($action, 'search_intent', 'informational') : 'informational',
            'target_audience' => $action ? $this->payloadText($action, 'target_audience', $action->objective?->audience ?: 'Readers and buyers') : 'Readers and buyers',
            'audience' => $action ? $this->payloadText($action, 'target_audience', $action->objective?->audience ?: 'Readers and buyers') : 'Readers and buyers',
            'funnel_stage' => $action ? $this->payloadText($action, 'funnel_stage', 'consideration') : 'consideration',
            'search_intent' => $action ? $this->payloadText($action, 'search_intent', 'informational') : 'informational',
            'unique_angle' => $action ? $this->payloadText($action, 'angle', $this->payloadText($action, 'reason')) : null,
            'key_points' => $action ? $this->articleKeyPoints($action) : [],
            'call_to_action' => $action ? $this->payloadText($action, 'suggested_cta', 'Explore PublishLayer') : 'Explore PublishLayer',
            'notes' => $action ? $this->briefingNotes($action) : null,
            'client_refs' => [
                'source' => 'agentic_marketing',
                'auto_created_from_content' => true,
                'agentic_marketing_action_id' => $action ? (string) $action->id : null,
                'agentic_marketing_opportunity_id' => $action ? (string) $action->opportunity_id : null,
                'proposal_details' => $action ? ($action->payload['proposal_details'] ?? null) : null,
                'review_required' => true,
            ],
        ]);
    }

    private function articleClientSiteId(AgenticMarketingAction $action, string $workspaceId): ?string
    {
        $siteId = $this->payloadText($action, 'client_site_id')
            ?: $this->payloadText($action, 'site_id')
            ?: ($action->objective?->client_site_id ? (string) $action->objective->client_site_id : null)
            ?: (data_get($action->opportunity?->payload, 'client_site_id') ? (string) data_get($action->opportunity?->payload, 'client_site_id') : null);

        if ($siteId) {
            return $siteId;
        }

        $siteIds = ClientSite::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhereIn('status', ['active', 'connected']);
            })
            ->orderByDesc('is_active')
            ->orderBy('created_at')
            ->limit(2)
            ->pluck('id');

        return $siteIds->count() === 1 ? (string) $siteIds->first() : null;
    }

    /**
     * @return array<int,string>
     */
    private function articleKeyPoints(AgenticMarketingAction $action): array
    {
        $items = collect((array) data_get($action->payload, 'proposal_details.items', []))
            ->map(function (mixed $item): ?string {
                $item = (array) $item;

                return match ((string) ($item['type'] ?? '')) {
                    'visibility_reasoning' => (string) ($item['reason'] ?? ''),
                    'estimated_impact' => (string) ($item['reason'] ?? ''),
                    'semantic_entities' => 'Cover entities: '.implode(', ', array_slice((array) ($item['entities'] ?? []), 0, 6)),
                    default => null,
                };
            })
            ->filter()
            ->values();

        if ($items->isNotEmpty()) {
            return $items->all();
        }

        return array_values(array_filter([
            $this->payloadText($action, 'reason'),
            $this->payloadText($action, 'recommendation'),
        ]));
    }

    private function briefingNotes(AgenticMarketingAction $action): string
    {
        $links = collect((array) data_get($action->payload, 'proposal_details.items', []))
            ->firstWhere('type', 'suggested_links');

        $notes = array_filter([
            $this->payloadText($action, 'recommendation'),
            $links ? 'Internal links: '.collect((array) data_get($links, 'links', []))
                ->map(fn (mixed $link): string => trim((string) data_get((array) $link, 'target')))
                ->filter()
                ->implode(', ') : null,
        ]);

        return implode("\n\n", $notes);
    }

    private function articleDraftHtml(AgenticMarketingAction $action, string $title, string $summary): string
    {
        $topic = $this->articleTopic($action, $title);
        $audience = $this->publicAudience($this->payloadText($action, 'target_audience', $action->objective?->audience ?: 'marketing and content teams'));
        $answerBlock = $this->publicText($this->proposalItemText($action, 'generated_answer_block', 'answer'));
        $angle = $this->publicText($this->payloadText($action, 'angle', $answerBlock ?: 'Use this page to explain the topic clearly, show why it matters, and help readers decide what to do next.'));
        $cta = $this->payloadText($action, 'suggested_cta', $this->proposalItemText($action, 'generated_cta', 'label') ?: 'Explore PublishLayer');
        $searchIntent = $this->payloadText($action, 'search_intent', $this->payloadText($action, 'primary_search_intent', 'informational'));
        $links = collect((array) data_get($action->payload, 'proposal_details.items', []))
            ->firstWhere('type', 'suggested_links');
        $linkItems = collect((array) data_get($links, 'links', []))
            ->map(fn (mixed $link): string => '<li>'.e((string) data_get((array) $link, 'target', data_get((array) $link, 'anchor_text', 'Related resource'))).'</li>')
            ->implode('');
        $directAnswer = $this->publicArticleIntro($title, $topic, $angle, $summary, $answerBlock);

        return '<h1>'.e($title).'</h1>'
            .'<p>'.e($directAnswer).'</p>'
            .'<h2>What '.e($topic).' means</h2>'
            .'<p>'.e($this->topicDefinition($topic, $audience)).'</p>'
            .'<h2>Why it matters for AI visibility</h2>'
            .'<p>'.e($angle).'</p>'
            .'<p>'.e($this->intentParagraph($topic, $searchIntent)).'</p>'
            .'<h2>How to use '.e($topic).' in your content workflow</h2>'
            .'<ol><li>Map the pages, guides, and resources that should represent your expertise.</li><li>Describe each resource with clear titles, summaries, and canonical URLs.</li><li>Keep the file aligned with your live content inventory so AI systems see current, useful resources.</li><li>Review performance signals and update the structure when your content strategy changes.</li></ol>'
            .'<h2>Common mistakes to avoid</h2>'
            .'<ul><li>Treating '.e($topic).' as a one-time technical file instead of an owned content asset.</li><li>Listing every page without prioritizing the resources that answer important buyer questions.</li><li>Linking to drafts, inactive URLs, or translated pages that are not actually live.</li></ul>'
            .($linkItems !== '' ? '<h2>Related resources</h2><ul>'.$linkItems.'</ul>' : '')
            .'<h2>Frequently asked questions</h2>'
            .'<h3>Who should own '.e($topic).'?</h3><p>Marketing, content, and development teams should manage it together: marketing defines the priority resources, content keeps the messaging accurate, and development ensures the file is reachable and maintained.</p>'
            .'<h3>How often should it be updated?</h3><p>Update it whenever important content is published, removed, translated, or repositioned in the customer journey.</p>'
            .'<h2>Next step</h2><p>'.e($cta).'</p>';
    }

    private function articleTopic(AgenticMarketingAction $action, string $title): string
    {
        $topic = $this->payloadText(
            $action,
            'primary_keyword',
            $this->payloadText($action, 'topic', (string) data_get($action->payload, 'proposal_details.topic', $title))
        );

        $topic = trim((string) preg_replace('/\s+guide$/i', '', $topic));

        return $topic !== '' ? $topic : $title;
    }

    private function publicAudience(string $audience): string
    {
        $audience = trim($audience);

        if ($audience === '' || preg_match('/planned campaign cluster|readers and buyers/i', $audience)) {
            return 'marketing, content, and development teams';
        }

        return rtrim($audience, '.');
    }

    private function proposalItemText(AgenticMarketingAction $action, string $type, string $field): string
    {
        $item = collect((array) data_get($action->payload, 'proposal_details.items', []))
            ->firstWhere('type', $type);

        return trim((string) data_get($item, $field, ''));
    }

    private function publicText(string $text): string
    {
        return trim((string) preg_replace(
            [
                '/Readers and buyers in the planned campaign cluster\.?/i',
                '/\bthe planned campaign cluster\b/i',
            ],
            [
                'marketing, content, and development teams',
                'the topic',
            ],
            $text
        ));
    }

    private function publicArticleIntro(string $title, string $topic, string $angle, string $summary, string $answerBlock = ''): string
    {
        if (trim($answerBlock) !== '') {
            return trim($answerBlock);
        }

        $cleanSummary = trim($summary);
        if ($cleanSummary !== '' && ! preg_match('/\b(create|prepare|review|draft|asset|editorial)\b/i', $cleanSummary)) {
            return $cleanSummary;
        }

        return sprintf(
            '%s explains what %s is, why it matters for AI visibility, and how teams can turn it into a practical content asset instead of another internal planning document.',
            $title,
            $topic
        );
    }

    private function topicDefinition(string $topic, string $audience): string
    {
        return sprintf(
            '%s is a structured way to help %s understand which resources on a site are authoritative, useful, and ready to reference. It works best when it reflects real published content and clear buyer intent.',
            $topic,
            $audience
        );
    }

    private function intentParagraph(string $topic, string $searchIntent): string
    {
        return sprintf(
            'For %s searches with %s intent, the goal is not to describe an internal task list. The page should answer the reader directly, explain the decision context, and show what a useful implementation looks like.',
            $topic,
            $searchIntent
        );
    }

    private function resolveContent(AgenticMarketingAction $action): ?Content
    {
        $contentId = $action->content_id ?: $this->payloadText($action, 'content_id');

        if (! $contentId) {
            return null;
        }

        return Content::query()
            ->with(['drafts' => fn ($query) => $query->latest('created_at'), 'workspace'])
            ->find((string) $contentId);
    }

    private function assertActionTenantSafety(AgenticMarketingAction $action): void
    {
        $action->loadMissing(['objective.workspace', 'objective.clientSite.workspace', 'opportunity.objective', 'run.objective', 'content.workspace', 'content.clientSite.workspace', 'draft.clientSite.workspace', 'draft.content.workspace']);

        $organizationId = $action->objective?->organization_id;
        if (! $organizationId) {
            throw new RuntimeException('Agentic Marketing action is missing an organization context.');
        }

        $workspaceId = $action->objective?->workspace_id;
        if ($workspaceId) {
            $this->assertWorkspaceReference((string) $workspaceId, (int) $organizationId);
        }

        if ($action->objective?->client_site_id) {
            $this->assertClientSiteReference((string) $action->objective->client_site_id, (int) $organizationId, $workspaceId ? (string) $workspaceId : null);
        }

        if ($action->opportunity && (string) $action->opportunity->objective_id !== (string) $action->objective_id) {
            throw new RuntimeException('Agentic Marketing opportunity does not belong to this action objective.');
        }

        if ($action->run && (string) $action->run->objective_id !== (string) $action->objective_id) {
            throw new RuntimeException('Agentic Marketing run does not belong to this action objective.');
        }

        foreach (array_filter([(string) $action->content_id, (string) $this->payloadText($action, 'content_id')]) as $contentId) {
            $this->assertContentReference($contentId, (int) $organizationId, $workspaceId ? (string) $workspaceId : null);
        }

        foreach (array_filter([(string) $action->draft_id, (string) $this->payloadText($action, 'draft_id')]) as $draftId) {
            $this->assertDraftReference($draftId, (int) $organizationId, $workspaceId ? (string) $workspaceId : null);
        }

        foreach (array_filter([(string) $this->payloadText($action, 'workspace_id')]) as $payloadWorkspaceId) {
            $this->assertWorkspaceReference($payloadWorkspaceId, (int) $organizationId);
            if ($workspaceId && (string) $workspaceId !== $payloadWorkspaceId) {
                throw new RuntimeException('Referenced workspace is outside this Agentic Marketing objective.');
            }
        }

        foreach (array_filter([(string) $this->payloadText($action, 'client_site_id'), (string) $this->payloadText($action, 'site_id')]) as $siteId) {
            $this->assertClientSiteReference($siteId, (int) $organizationId, $workspaceId ? (string) $workspaceId : null);
        }
    }

    private function assertWorkspaceReference(string $workspaceId, int $organizationId): void
    {
        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace || (int) $workspace->organization_id !== $organizationId) {
            throw new RuntimeException('Referenced workspace is outside this organization.');
        }
    }

    private function assertContentReference(string $contentId, int $organizationId, ?string $workspaceId = null): void
    {
        $content = Content::query()->with(['workspace', 'clientSite.workspace'])->find($contentId);
        if (! $content) {
            throw new RuntimeException('Referenced content does not exist.');
        }

        $contentOrganizationId = (int) ($content->workspace?->organization_id ?? $content->clientSite?->workspace?->organization_id ?? 0);
        if ($contentOrganizationId !== $organizationId) {
            throw new RuntimeException('Referenced content is outside this organization.');
        }

        if ($workspaceId && (string) $content->workspace_id !== $workspaceId) {
            throw new RuntimeException('Referenced content is outside this workspace.');
        }
    }

    private function assertDraftReference(string $draftId, int $organizationId, ?string $workspaceId = null): void
    {
        $draft = Draft::query()->with(['clientSite.workspace', 'content.workspace'])->find($draftId);
        if (! $draft) {
            throw new RuntimeException('Referenced draft does not exist.');
        }

        $draftOrganizationId = (int) ($draft->clientSite?->workspace?->organization_id ?? $draft->content?->workspace?->organization_id ?? 0);
        if ($draftOrganizationId !== $organizationId) {
            throw new RuntimeException('Referenced draft is outside this organization.');
        }

        if ($workspaceId) {
            $draftWorkspaceId = (string) ($draft->clientSite?->workspace_id ?? $draft->content?->workspace_id ?? '');
            if ($draftWorkspaceId !== $workspaceId) {
                throw new RuntimeException('Referenced draft is outside this workspace.');
            }
        }
    }

    private function assertClientSiteReference(string $siteId, int $organizationId, ?string $workspaceId = null): void
    {
        $site = ClientSite::query()->with('workspace')->find($siteId);
        if (! $site || (int) ($site->workspace?->organization_id ?? 0) !== $organizationId) {
            throw new RuntimeException('Referenced site is outside this organization.');
        }

        if ($workspaceId && (string) $site->workspace_id !== $workspaceId) {
            throw new RuntimeException('Referenced site is outside this workspace.');
        }
    }

    private function proposalResult(string $summary, array $suggestions = [], array $warnings = [], string $serviceUsed = 'safe_proposal'): array
    {
        return [
            'summary' => $summary,
            'created_content_id' => null,
            'created_draft_id' => null,
            'suggestions' => $suggestions,
            'warnings' => $warnings,
            'service_used' => $serviceUsed,
        ];
    }

    private function dryRunResult(AgenticMarketingAction $action, string $summary, array $extra = []): array
    {
        return array_merge($this->proposalResult(
            summary: $summary,
            suggestions: [$this->recommendation($action, 'Dry-run only. Review the proposed action before applying changes.')],
            serviceUsed: 'policy_dry_run'
        ), [
            'dry_run' => true,
        ], $extra);
    }

    /**
     * @param array<int,string> $fields
     * @return array<string,mixed>
     */
    private function rollbackMetadata(Content $content, array $fields): array
    {
        return [
            'content_id' => (string) $content->id,
            'fields' => collect($fields)
                ->mapWithKeys(fn (string $field): array => [$field => $content->{$field}])
                ->all(),
            'captured_at' => now()->toIso8601String(),
            'instructions' => 'Restore these field values to roll back the Agentic Marketing auto-apply change.',
        ];
    }

    private function normalizeResult(array $result): array
    {
        return array_merge([
            'summary' => 'Action completed.',
            'created_content_id' => null,
            'created_draft_id' => null,
            'suggestions' => [],
            'warnings' => [],
            'service_used' => 'safe_executor',
            'executed_at' => now()->toIso8601String(),
        ], $result);
    }

    private function recommendation(AgenticMarketingAction $action, string $fallback): array
    {
        return [
            'type' => 'recommendation',
            'text' => $this->payloadText($action, 'recommendation', $fallback),
            'reason' => $this->payloadText($action, 'reason'),
            'review_required' => true,
        ];
    }

    private function payloadText(AgenticMarketingAction $action, string $key, mixed $fallback = null): ?string
    {
        $value = data_get($action->payload ?? [], $key, $fallback);

        return is_scalar($value) ? trim((string) $value) : $fallback;
    }

    private function failAction(AgenticMarketingAction $action, string $message, ?Throwable $exception = null): void
    {
        $action->forceFill([
            'status' => AgenticMarketingAction::STATUS_FAILED,
            'error_message' => $message,
            'failed_at' => now(),
            'completed_at' => null,
            'result' => $this->normalizeResult([
                'summary' => 'Action failed before making changes.',
                'warnings' => [$message],
                'service_used' => 'safe_executor',
            ]),
        ])->save();

        Log::warning('agentic_marketing.action.execution_failed', array_merge($this->logContext($action), [
            'error' => $message,
            'exception' => $exception ? $exception::class : null,
        ]));
    }

    private function friendlyMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        return $message !== ''
            ? Str::limit($message, 240)
            : 'The action could not be executed safely. Please review the action and try again.';
    }

    private function logContext(AgenticMarketingAction $action): array
    {
        return [
            'action_id' => (string) $action->id,
            'objective_id' => (string) $action->objective_id,
            'action_type' => (string) $action->action_type,
            'status' => (string) $action->status,
            'organization_id' => $action->objective?->organization_id,
            'workspace_id' => $action->objective?->workspace_id,
        ];
    }
}
