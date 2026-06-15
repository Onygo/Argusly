<?php

namespace App\Services\AgenticMarketing;

use App\Models\AgenticMarketingExecutionSetting;

class AutonomyPresetService
{
    public const GUIDED_MODE = 'guided_mode';
    public const DRAFT_ASSIST = 'draft_assist';
    public const CONTENT_AUTOPILOT = 'content_autopilot';
    public const GROWTH_AUTOPILOT = 'growth_autopilot';
    public const FULL_SUPERVISED_AUTONOMY = 'full_supervised_autonomy';

    /**
     * @return array<string,array<string,mixed>>
     */
    public function presets(): array
    {
        return [
            self::GUIDED_MODE => [
                'label' => 'Guided Mode',
                'summary' => 'Argusly recommends work, but every meaningful action waits for approval.',
                'allowed_actions' => ['recommendations', 'opportunity triage'],
                'approval_requirements' => 'Approval required for all prepared work.',
                'risk_threshold' => 'Manual approval for every action',
                'credit_limit' => '50 credits per month',
                'publishing_permissions' => 'Publishing disabled',
                'settings' => [
                    'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_GUIDED,
                    'autonomous_brief_generation_enabled' => false,
                    'autonomous_chained_plans_enabled' => false,
                    'autonomous_refresh_enabled' => false,
                    'autonomous_internal_linking_enabled' => false,
                    'autonomous_publication_enabled' => false,
                    'max_autonomous_actions_per_day' => 1,
                    'max_autonomous_credits_per_month' => 50,
                    'require_approval_above_priority_score' => 0,
                    'require_approval_for_new_pages' => true,
                    'require_approval_for_external_publication' => true,
                    'notification_email_enabled' => true,
                ],
            ],
            self::DRAFT_ASSIST => [
                'label' => 'Draft Assist',
                'summary' => 'Argusly prepares briefs and content plans for review, without publishing.',
                'allowed_actions' => ['brief generation', 'content planning', 'draft preparation'],
                'approval_requirements' => 'Approval required before execution and publishing.',
                'risk_threshold' => 'Approval above priority score 60',
                'credit_limit' => '150 credits per month',
                'publishing_permissions' => 'Publishing disabled',
                'settings' => [
                    'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_AUTONOMOUS,
                    'autonomous_brief_generation_enabled' => true,
                    'autonomous_chained_plans_enabled' => true,
                    'autonomous_refresh_enabled' => false,
                    'autonomous_internal_linking_enabled' => false,
                    'autonomous_publication_enabled' => false,
                    'max_autonomous_actions_per_day' => 3,
                    'max_autonomous_credits_per_month' => 150,
                    'require_approval_above_priority_score' => 60,
                    'require_approval_for_new_pages' => true,
                    'require_approval_for_external_publication' => true,
                    'notification_email_enabled' => true,
                ],
            ],
            self::CONTENT_AUTOPILOT => [
                'label' => 'Content Autopilot',
                'summary' => 'Argusly prepares briefs, drafts, refreshes, and internal links while keeping publishing supervised.',
                'allowed_actions' => ['brief generation', 'draft preparation', 'refresh actions', 'internal linking'],
                'approval_requirements' => 'Approval required for new pages, external publishing, and higher priority items.',
                'risk_threshold' => 'Approval above priority score 75',
                'credit_limit' => '350 credits per month',
                'publishing_permissions' => 'Publishing disabled',
                'settings' => [
                    'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_AUTONOMOUS,
                    'autonomous_brief_generation_enabled' => true,
                    'autonomous_chained_plans_enabled' => true,
                    'autonomous_refresh_enabled' => true,
                    'autonomous_internal_linking_enabled' => true,
                    'autonomous_publication_enabled' => false,
                    'max_autonomous_actions_per_day' => 6,
                    'max_autonomous_credits_per_month' => 350,
                    'require_approval_above_priority_score' => 75,
                    'require_approval_for_new_pages' => true,
                    'require_approval_for_external_publication' => true,
                    'notification_email_enabled' => true,
                ],
            ],
            self::GROWTH_AUTOPILOT => [
                'label' => 'Growth Autopilot',
                'summary' => 'Argusly can execute low-risk growth work and prepare publishing decisions for approval.',
                'allowed_actions' => ['content planning', 'refreshes', 'internal links', 'low-risk publication prep'],
                'approval_requirements' => 'Approval required for new pages, external publishing, and high-priority items.',
                'risk_threshold' => 'Approval above priority score 85',
                'credit_limit' => '750 credits per month',
                'publishing_permissions' => 'Publishing allowed only when destination and approval rules allow it',
                'settings' => [
                    'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_AUTONOMOUS,
                    'autonomous_brief_generation_enabled' => true,
                    'autonomous_chained_plans_enabled' => true,
                    'autonomous_refresh_enabled' => true,
                    'autonomous_internal_linking_enabled' => true,
                    'autonomous_publication_enabled' => true,
                    'max_autonomous_actions_per_day' => 10,
                    'max_autonomous_credits_per_month' => 750,
                    'require_approval_above_priority_score' => 85,
                    'require_approval_for_new_pages' => true,
                    'require_approval_for_external_publication' => true,
                    'notification_email_enabled' => true,
                ],
            ],
            self::FULL_SUPERVISED_AUTONOMY => [
                'label' => 'Full Supervised Autonomy',
                'summary' => 'Argusly runs the broadest supervised workflow with governance, budgets, and publication limits still enforced.',
                'allowed_actions' => ['briefs', 'content packages', 'refreshes', 'internal links', 'approved publishing'],
                'approval_requirements' => 'Approval required for external publishing and the highest priority items.',
                'risk_threshold' => 'Approval above priority score 95',
                'credit_limit' => '1,500 credits per month',
                'publishing_permissions' => 'Publishing allowed within selected sites and destinations',
                'settings' => [
                    'agentic_execution_mode' => AgenticMarketingExecutionSetting::MODE_AUTONOMOUS,
                    'autonomous_brief_generation_enabled' => true,
                    'autonomous_chained_plans_enabled' => true,
                    'autonomous_refresh_enabled' => true,
                    'autonomous_internal_linking_enabled' => true,
                    'autonomous_publication_enabled' => true,
                    'max_autonomous_actions_per_day' => 20,
                    'max_autonomous_credits_per_month' => 1500,
                    'require_approval_above_priority_score' => 95,
                    'require_approval_for_new_pages' => false,
                    'require_approval_for_external_publication' => true,
                    'notification_email_enabled' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function settingsFor(string $preset): array
    {
        return $this->presets()[$preset]['settings'] ?? $this->presets()[self::GUIDED_MODE]['settings'];
    }

    /**
     * @return array<int,string>
     */
    public function keys(): array
    {
        return array_keys($this->presets());
    }

    public function labelFor(?string $preset): string
    {
        return (string) data_get($this->presets(), ($preset ?: self::GUIDED_MODE).'.label', 'Guided Mode');
    }
}
