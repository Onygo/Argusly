<?php

namespace App\Services\AgenticMarketing;

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\AgenticMarketingObjective;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class AgenticApprovalGate
{
    public const DECISION_ALLOWED = 'allowed';
    public const DECISION_REQUIRES_APPROVAL = 'requires_approval';
    public const DECISION_BLOCKED = 'blocked';

    public const ACTION_GENERATE_BRIEF = 'generate_brief';
    public const ACTION_CREATE_CHAINED_PLAN = 'create_chained_plan';
    public const ACTION_REFRESH_EXISTING_CONTENT = 'refresh_existing_content';
    public const ACTION_CREATE_NEW_CONTENT = 'create_new_content';
    public const ACTION_PUBLISH_CONTENT = 'publish_content';
    public const ACTION_REPUBLISH_CONTENT = 'republish_content';
    public const ACTION_ADD_INTERNAL_LINKS = 'add_internal_links';
    public const ACTION_UPDATE_ANSWER_BLOCKS = 'update_answer_blocks';
    public const ACTION_RUN_AI_VISIBILITY_REFRESH = 'run_ai_visibility_refresh';
    public const ACTION_CREATE_CAMPAIGN_CLUSTER = 'create_campaign_cluster';

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function forAction(string $action, Workspace|AgenticMarketingObjective|null $scope, array $context = []): array
    {
        $workspace = $scope instanceof Workspace ? $scope : $scope?->workspace;
        if (! $workspace && $scope instanceof AgenticMarketingObjective && $scope->workspace_id) {
            $workspace = Workspace::query()->find($scope->workspace_id);
        }

        if (! $workspace) {
            return $this->blocked($action, 'A workspace is required before an Agentic Marketing action can run.', null, $context);
        }

        $setting = $this->settingsFor($workspace, $context);
        $siteId = $this->resolveSiteId($scope, $context);
        $estimatedCredits = $this->estimatedCredits($context);
        $approvalGranted = (bool) ($context['has_customer_approval'] ?? false);
        $priorityScore = $this->priorityScore($context);
        $policySnapshot = $this->policySnapshot($setting, $siteId, $estimatedCredits, $priorityScore);

        if (! $this->isSupportedAction($action)) {
            return $this->blocked($action, 'Unsupported Agentic Marketing action.', $setting, $context, $policySnapshot);
        }

        if ($this->isUnsafeOrIncomplete($context)) {
            return $this->blocked($action, 'Content is unsafe or incomplete and must be reviewed before execution.', $setting, $context, $policySnapshot);
        }

        if ($this->requiresPublishingSite($action, $setting) && ! $siteId) {
            return $this->blocked($action, 'Select a publishing site before this Agentic Marketing action can run.', $setting, $context, $policySnapshot);
        }

        if ($siteId && $setting->isAutonomous() && ! in_array($siteId, (array) ($setting->allowed_site_ids ?? []), true)) {
            return $this->blocked($action, 'This publishing site is not allowed for autonomous Agentic Marketing execution.', $setting, $context, $policySnapshot);
        }

        if ($setting->isGuided()) {
            if ($this->isPublicationAction($action)) {
                $policySnapshot = $this->withPublicationSafety($workspace, $siteId, $setting, $context, $policySnapshot);

                if ((bool) data_get($policySnapshot, 'content_safety.block', false)) {
                    return $this->blocked($action, 'Pre-publication safety checks blocked this action.', $setting, $context, $policySnapshot);
                }
            }

            if ($approvalGranted) {
                return $this->allowed($action, 'Customer approval is present for guided execution.', $setting, $context, $policySnapshot);
            }

            return $this->requiresApproval($action, 'Guided mode requires customer approval before execution.', 'guided_customer_approval', $setting, $context, $policySnapshot);
        }

        if (! $this->autonomousCapabilityEnabled($setting, $action)) {
            return $this->requiresApproval($action, 'Autonomous mode does not allow this action type.', 'action_type_not_enabled', $setting, $context, $policySnapshot);
        }

        if ($this->isNewPageAction($action) && $setting->require_approval_for_new_pages && ! $approvalGranted) {
            return $this->requiresApproval($action, 'New pages require customer approval under this autonomous policy.', 'new_page_approval', $setting, $context, $policySnapshot);
        }

        if ($this->isPublicationAction($action) && $this->isExternalPublication($context) && $setting->require_approval_for_external_publication && ! $approvalGranted) {
            return $this->requiresApproval($action, 'External publication requires customer approval under this autonomous policy.', 'external_publication_approval', $setting, $context, $policySnapshot);
        }

        if ($priorityScore !== null && $priorityScore > (int) $setting->require_approval_above_priority_score && ! $approvalGranted) {
            return $this->requiresApproval($action, 'High-priority Agentic Marketing actions require customer approval.', 'priority_threshold', $setting, $context, $policySnapshot);
        }

        if ($this->isHighCost($setting, $estimatedCredits) && ! $approvalGranted) {
            return $this->requiresApproval($action, 'High-cost Agentic Marketing actions require customer approval.', 'high_credit_impact', $setting, $context, $policySnapshot);
        }

        if ($this->isPublicationAction($action)) {
            $policySnapshot = $this->withPublicationSafety($workspace, $siteId, $setting, $context, $policySnapshot);

            if ((bool) data_get($policySnapshot, 'content_safety.block', false)) {
                return $this->blocked($action, 'Pre-publication safety checks blocked this action.', $setting, $context, $policySnapshot);
            }
        }

        if ($this->monthlyCreditLimitExceeded($workspace, $setting, $estimatedCredits)) {
            return $this->blocked($action, 'Autonomous monthly credit limit would be exceeded.', $setting, $context, $policySnapshot);
        }

        return $this->allowed($action, 'Autonomous policy allows this action now.', $setting, $context, $policySnapshot);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function forMarketingAction(AgenticMarketingAction $action, array $context = []): array
    {
        $action->loadMissing(['objective.workspace', 'opportunity', 'content']);
        $mappedAction = $this->mapMarketingActionType((string) $action->action_type);
        $payload = (array) ($action->payload ?? []);
        $opportunityPayload = (array) ($action->opportunity?->payload ?? []);
        $content = $action->content;

        return $this->forAction($mappedAction, $action->objective, array_replace_recursive([
            'agentic_marketing_action_id' => (string) $action->id,
            'site_id' => data_get($payload, 'client_site_id') ?: data_get($payload, 'site_id') ?: $action->objective?->client_site_id ?: $content?->client_site_id,
            'content_id' => $action->content_id ?: data_get($payload, 'content_id'),
            'estimated_credit_impact' => $action->estimated_credits ?? data_get($payload, 'planning.estimated_credits'),
            'priority_score' => $action->opportunity?->priority_score ?? data_get($opportunityPayload, 'priority_score'),
            'is_external_publication' => true,
            'content_complete' => $this->contentLooksComplete($content),
            'unsafe' => (bool) data_get($payload, 'safety.unsafe', false),
        ], $context));
    }

    private function settingsFor(Workspace $workspace, array $context): AgenticMarketingExecutionSetting
    {
        $brandVoiceId = trim((string) ($context['brand_voice_id'] ?? ''));

        $query = AgenticMarketingExecutionSetting::query()
            ->where('workspace_id', $workspace->id);

        $setting = $brandVoiceId !== ''
            ? (clone $query)->where('brand_voice_id', $brandVoiceId)->first()
            : null;

        return $setting
            ?: $query->whereNull('brand_voice_id')->first()
            ?: AgenticMarketingExecutionSetting::defaultsFor($workspace);
    }

    private function isSupportedAction(string $action): bool
    {
        return in_array($action, [
            self::ACTION_GENERATE_BRIEF,
            self::ACTION_CREATE_CHAINED_PLAN,
            self::ACTION_REFRESH_EXISTING_CONTENT,
            self::ACTION_CREATE_NEW_CONTENT,
            self::ACTION_PUBLISH_CONTENT,
            self::ACTION_REPUBLISH_CONTENT,
            self::ACTION_ADD_INTERNAL_LINKS,
            self::ACTION_UPDATE_ANSWER_BLOCKS,
            self::ACTION_RUN_AI_VISIBILITY_REFRESH,
            self::ACTION_CREATE_CAMPAIGN_CLUSTER,
        ], true);
    }

    private function mapMarketingActionType(string $actionType): string
    {
        return match ($actionType) {
            'refresh_article' => self::ACTION_REFRESH_EXISTING_CONTENT,
            'improve_internal_links' => self::ACTION_ADD_INTERNAL_LINKS,
            'add_answer_block' => self::ACTION_UPDATE_ANSWER_BLOCKS,
            'create_article' => self::ACTION_CREATE_NEW_CONTENT,
            'create_locale_variant' => self::ACTION_CREATE_NEW_CONTENT,
            'update_meta', 'add_schema' => self::ACTION_REFRESH_EXISTING_CONTENT,
            default => $actionType,
        };
    }

    private function autonomousCapabilityEnabled(AgenticMarketingExecutionSetting $setting, string $action): bool
    {
        return match ($action) {
            self::ACTION_GENERATE_BRIEF => (bool) $setting->autonomous_brief_generation_enabled,
            self::ACTION_CREATE_CHAINED_PLAN,
            self::ACTION_CREATE_CAMPAIGN_CLUSTER => (bool) $setting->autonomous_chained_plans_enabled,
            self::ACTION_REFRESH_EXISTING_CONTENT,
            self::ACTION_UPDATE_ANSWER_BLOCKS,
            self::ACTION_RUN_AI_VISIBILITY_REFRESH => (bool) $setting->autonomous_refresh_enabled,
            self::ACTION_CREATE_NEW_CONTENT => (bool) $setting->autonomous_brief_generation_enabled,
            self::ACTION_PUBLISH_CONTENT,
            self::ACTION_REPUBLISH_CONTENT => (bool) $setting->autonomous_publication_enabled,
            self::ACTION_ADD_INTERNAL_LINKS => (bool) $setting->autonomous_internal_linking_enabled,
            default => false,
        };
    }

    private function requiresPublishingSite(string $action, AgenticMarketingExecutionSetting $setting): bool
    {
        return $setting->isAutonomous() || in_array($action, [
            self::ACTION_PUBLISH_CONTENT,
            self::ACTION_REPUBLISH_CONTENT,
        ], true);
    }

    private function resolveSiteId(Workspace|AgenticMarketingObjective|null $scope, array $context): ?string
    {
        $siteId = trim((string) ($context['site_id'] ?? $context['client_site_id'] ?? ''));
        if ($siteId !== '') {
            return $siteId;
        }

        if ($scope instanceof AgenticMarketingObjective && $scope->client_site_id) {
            return (string) $scope->client_site_id;
        }

        $contentId = trim((string) ($context['content_id'] ?? ''));
        if ($contentId !== '') {
            $contentSiteId = Content::query()->whereKey($contentId)->value('client_site_id');
            if ($contentSiteId) {
                return (string) $contentSiteId;
            }
        }

        return null;
    }

    private function estimatedCredits(array $context): int
    {
        return max(0, (int) ($context['estimated_credit_impact'] ?? $context['estimated_credits'] ?? 0));
    }

    private function priorityScore(array $context): ?int
    {
        $value = $context['priority_score'] ?? null;

        return is_numeric($value) ? max(0, min(100, (int) $value)) : null;
    }

    private function isUnsafeOrIncomplete(array $context): bool
    {
        if ((bool) ($context['unsafe'] ?? false)) {
            return true;
        }

        return array_key_exists('content_complete', $context) && ! (bool) $context['content_complete'];
    }

    private function contentLooksComplete(?Content $content): bool
    {
        if (! $content) {
            return true;
        }

        return ! in_array((string) $content->status, ['failed', 'incomplete', 'needs_review'], true);
    }

    private function isNewPageAction(string $action): bool
    {
        return in_array($action, [
            self::ACTION_GENERATE_BRIEF,
            self::ACTION_CREATE_CHAINED_PLAN,
            self::ACTION_CREATE_NEW_CONTENT,
            self::ACTION_CREATE_CAMPAIGN_CLUSTER,
        ], true);
    }

    private function isPublicationAction(string $action): bool
    {
        return in_array($action, [
            self::ACTION_PUBLISH_CONTENT,
            self::ACTION_REPUBLISH_CONTENT,
        ], true);
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $policySnapshot
     * @return array<string,mixed>
     */
    private function withPublicationSafety(Workspace $workspace, ?string $siteId, AgenticMarketingExecutionSetting $setting, array $context, array $policySnapshot): array
    {
        $policySnapshot['content_safety'] = $this->publicationSafety($workspace, $siteId, $context + [
            'execution_mode' => (string) $setting->agentic_execution_mode,
        ]);

        return $policySnapshot;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function publicationSafety(Workspace $workspace, ?string $siteId, array $context): array
    {
        $contentId = trim((string) ($context['content_id'] ?? ''));
        $content = $contentId !== ''
            ? Content::query()->find($contentId)
            : null;
        $site = $siteId
            ? ClientSite::query()->find($siteId)
            : null;

        return app(AgenticContentSafetyService::class)->evaluate($content, $site, $workspace, $context);
    }

    private function isExternalPublication(array $context): bool
    {
        return (bool) ($context['is_external_publication'] ?? true);
    }

    private function isHighCost(AgenticMarketingExecutionSetting $setting, int $estimatedCredits): bool
    {
        if ($estimatedCredits <= 0) {
            return false;
        }

        return $estimatedCredits >= $this->highCostThreshold($setting);
    }

    private function highCostThreshold(AgenticMarketingExecutionSetting $setting): int
    {
        return max(25, (int) ceil(((int) $setting->max_autonomous_credits_per_month) * 0.25));
    }

    private function monthlyCreditLimitExceeded(Workspace $workspace, AgenticMarketingExecutionSetting $setting, int $estimatedCredits): bool
    {
        $limit = (int) $setting->max_autonomous_credits_per_month;
        if ($limit <= 0 || $estimatedCredits <= 0) {
            return false;
        }

        $monthStart = now()->startOfMonth();
        $committed = (int) AgenticMarketingAction::query()
            ->whereHas('objective', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('created_at', '>=', $monthStart)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('credits_captured')
                    ->orWhereNotNull('credits_reserved');
            })
            ->sum(DB::raw('COALESCE(credits_captured, credits_reserved, 0)'));

        return ($committed + $estimatedCredits) > $limit;
    }

    /**
     * @return array<string,mixed>
     */
    private function policySnapshot(AgenticMarketingExecutionSetting $setting, ?string $siteId, int $estimatedCredits, ?int $priorityScore): array
    {
        return [
            'setting_id' => $setting->exists ? (string) $setting->id : null,
            'workspace_id' => (string) $setting->workspace_id,
            'brand_voice_id' => $setting->brand_voice_id ? (string) $setting->brand_voice_id : null,
            'mode' => (string) $setting->agentic_execution_mode,
            'enabled_actions' => [
                'publication' => (bool) $setting->autonomous_publication_enabled,
                'refresh' => (bool) $setting->autonomous_refresh_enabled,
                'internal_linking' => (bool) $setting->autonomous_internal_linking_enabled,
                'brief_generation' => (bool) $setting->autonomous_brief_generation_enabled,
                'chained_plans' => (bool) $setting->autonomous_chained_plans_enabled,
            ],
            'limits' => [
                'max_autonomous_actions_per_day' => (int) $setting->max_autonomous_actions_per_day,
                'max_autonomous_credits_per_month' => (int) $setting->max_autonomous_credits_per_month,
                'high_cost_threshold' => $this->highCostThreshold($setting),
                'require_approval_above_priority_score' => (int) $setting->require_approval_above_priority_score,
            ],
            'rules' => [
                'require_approval_for_new_pages' => (bool) $setting->require_approval_for_new_pages,
                'require_approval_for_external_publication' => (bool) $setting->require_approval_for_external_publication,
                'allowed_site_ids' => array_values((array) ($setting->allowed_site_ids ?? [])),
                'allowed_publishing_destination_ids' => array_values((array) ($setting->allowed_publishing_destination_ids ?? [])),
            ],
            'evaluation' => [
                'site_id' => $siteId,
                'estimated_credit_impact' => $estimatedCredits,
                'priority_score' => $priorityScore,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $policySnapshot
     * @return array<string,mixed>
     */
    private function allowed(string $action, string $reason, ?AgenticMarketingExecutionSetting $setting, array $context, ?array $policySnapshot = null): array
    {
        return $this->decision(self::DECISION_ALLOWED, $action, $reason, null, $setting, $context, $policySnapshot);
    }

    /**
     * @param  array<string,mixed>|null  $policySnapshot
     * @return array<string,mixed>
     */
    private function requiresApproval(string $action, string $reason, string $approvalType, ?AgenticMarketingExecutionSetting $setting, array $context, ?array $policySnapshot = null): array
    {
        return $this->decision(self::DECISION_REQUIRES_APPROVAL, $action, $reason, $approvalType, $setting, $context, $policySnapshot);
    }

    /**
     * @param  array<string,mixed>|null  $policySnapshot
     * @return array<string,mixed>
     */
    private function blocked(string $action, string $reason, ?AgenticMarketingExecutionSetting $setting, array $context, ?array $policySnapshot = null): array
    {
        return $this->decision(self::DECISION_BLOCKED, $action, $reason, 'blocked', $setting, $context, $policySnapshot);
    }

    /**
     * @param  array<string,mixed>|null  $policySnapshot
     * @return array<string,mixed>
     */
    private function decision(string $decision, string $action, string $reason, ?string $approvalType, ?AgenticMarketingExecutionSetting $setting, array $context, ?array $policySnapshot): array
    {
        if ($policySnapshot) {
            $policySnapshot['evaluation']['has_customer_approval'] = (bool) ($context['has_customer_approval'] ?? false);
            $policySnapshot['evaluation']['requires_customer_approval'] = $decision === self::DECISION_REQUIRES_APPROVAL;
        }

        return [
            'allowed' => $decision === self::DECISION_ALLOWED,
            'requires_approval' => $decision === self::DECISION_REQUIRES_APPROVAL,
            'blocked' => $decision === self::DECISION_BLOCKED,
            'decision' => $decision,
            'action' => $action,
            'reason' => $reason,
            'required_approval_type' => $approvalType,
            'estimated_credit_impact' => $this->estimatedCredits($context),
            'policy_snapshot' => $policySnapshot ?: [
                'mode' => $setting?->agentic_execution_mode ?? AgenticMarketingExecutionSetting::MODE_GUIDED,
            ],
        ];
    }
}
