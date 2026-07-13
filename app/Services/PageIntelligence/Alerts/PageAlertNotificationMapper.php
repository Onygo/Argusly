<?php

namespace App\Services\PageIntelligence\Alerts;

use App\Models\AlertRule;
use App\Models\Notification;
use App\Models\PageAlert;
use App\Models\RecommendedAction;
use App\Services\BrandIntelligence\BrandIntelligenceContextService;
use App\Services\Notifications\NotificationService;

class PageAlertNotificationMapper
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly BrandIntelligenceContextService $brandIntelligence,
    ) {
    }

    public function notify(PageAlert $alert): PageAlert
    {
        $notification = $this->notifications->notifyWorkspace(
            workspaceId: (string) $alert->workspace_id,
            type: $this->notificationType($alert),
            title: $alert->title,
            body: $alert->summary,
            options: [
                'priority' => $this->priority($alert->severity),
                'cta_label' => $alert->monitored_page_id ? 'Inspect page' : 'Review alert',
                'cta_url' => $this->ctaUrl($alert),
                'dedupe_key' => 'page_alert:'.$alert->id,
                'meta' => [
                    'source' => 'page_intelligence_alert',
                    'page_alert_id' => $alert->id,
                    'alert_rule_id' => $alert->alert_rule_id,
                    'trigger' => $alert->trigger,
                    'severity' => $alert->severity,
                    'monitored_page_id' => $alert->monitored_page_id,
                    'signal_event_id' => $alert->signal_event_id,
                    'signal_detection_id' => $alert->signal_detection_id,
                ],
            ],
        );

        $recommendedAction = $this->shouldCreateRecommendedAction($alert)
            ? $this->upsertRecommendedAction($alert)
            : null;

        $alert->forceFill([
            'notification_id' => $notification->id,
            'recommended_action_id' => $recommendedAction?->id,
        ])->save();

        return $alert->refresh();
    }

    private function notificationType(PageAlert $alert): string
    {
        return in_array($alert->severity, ['high', 'critical'], true)
            ? Notification::TYPE_ACTION_REQUIRED
            : Notification::TYPE_SYSTEM;
    }

    private function priority(string $severity): int
    {
        return match ($severity) {
            'critical' => 100,
            'high' => 90,
            'medium' => 70,
            'low' => 50,
            default => 40,
        };
    }

    private function shouldCreateRecommendedAction(PageAlert $alert): bool
    {
        return in_array($alert->severity, ['high', 'critical'], true);
    }

    private function upsertRecommendedAction(PageAlert $alert): RecommendedAction
    {
        $signature = 'page_alert:'.$alert->id.':recommended_action';
        $blueprint = $this->actionBlueprint($alert);
        $brandIntelligence = $this->brandIntelligence->snapshotForWorkspace((string) $alert->workspace_id);

        return RecommendedAction::query()->updateOrCreate(
            ['source_signature' => $signature],
            [
                'workspace_id' => $alert->workspace_id,
                'organization_id' => $alert->organization_id,
                'source_type' => PageAlert::class,
                'source_id' => (string) $alert->id,
                'source_group' => RecommendedAction::SOURCE_AI_VISIBILITY,
                'action_type' => $blueprint['action_type'],
                'status' => RecommendedAction::STATUS_OPEN,
                'title' => $blueprint['title'],
                'summary' => $alert->summary,
                'why_this_matters' => $blueprint['why_this_matters'],
                'expected_outcome' => $blueprint['expected_outcome'],
                'what_argusly_will_do' => $blueprint['what_argusly_will_do'],
                'what_requires_approval' => $blueprint['what_requires_approval'],
                'estimated_effort' => $blueprint['estimated_effort'],
                'priority_score' => $this->priority($alert->severity),
                'confidence_score' => max(50, min(100, (int) data_get($alert->metrics_json, 'confidence', 80))),
                'expected_impact_score' => max(50, min(100, (int) data_get($alert->metrics_json, 'impact', $this->priority($alert->severity)))),
                'priority_label' => $alert->severity === 'critical' ? 'critical' : 'high',
                'confidence_label' => 'high',
                'expected_impact_label' => $blueprint['expected_impact_label'],
                'primary_cta_label' => $alert->monitored_page_id ? 'Inspect page' : 'Review alert',
                'primary_cta_url' => $this->ctaUrl($alert),
                'metadata' => [
                    'source' => 'page_intelligence_alert',
                    'page_alert_id' => $alert->id,
                    'alert_rule_id' => $alert->alert_rule_id,
                    'trigger' => $alert->trigger,
                    'monitored_page_id' => $alert->monitored_page_id,
                    'signal_event_id' => $alert->signal_event_id,
                    'signal_detection_id' => $alert->signal_detection_id,
                    'action_category' => $blueprint['category'],
                    'recommended_next_step' => $blueprint['recommended_next_step'],
                    'brand_intelligence' => $brandIntelligence,
                    'evidence_package' => [
                        'title' => $alert->title,
                        'summary' => $alert->summary,
                        'evidence' => $alert->evidence_json ?? [],
                        'metrics' => $alert->metrics_json ?? [],
                    ],
                ],
                'visible_at' => now(),
            ]
        );
    }

    /**
     * @return array{
     *     action_type:string,
     *     category:string,
     *     title:string,
     *     why_this_matters:string,
     *     expected_outcome:string,
     *     what_argusly_will_do:string,
     *     what_requires_approval:string,
     *     estimated_effort:string,
     *     expected_impact_label:string,
     *     recommended_next_step:string
     * }
     */
    private function actionBlueprint(PageAlert $alert): array
    {
        return match ($alert->trigger) {
            AlertRule::TRIGGER_NEGATIVE_SENTIMENT,
            AlertRule::TRIGGER_HIGH_RISK_NEGATIVE_PAGE => [
                'action_type' => 'prepare_reputation_response',
                'category' => 'risk_response',
                'title' => 'Prepare response for '.$alert->title,
                'why_this_matters' => 'This Page Intelligence alert indicates negative or risky framing on a monitored page.',
                'expected_outcome' => 'Confirm the risk, decide whether a content, sales or customer-facing response is needed, and keep the evidence attached to the decision.',
                'what_argusly_will_do' => 'Argusly keeps the alert linked to the monitored page, sentiment evidence, signal context and scoring inputs.',
                'what_requires_approval' => 'Approve any external response, content update, sales enablement note or campaign action before execution.',
                'estimated_effort' => RecommendedAction::EFFORT_MEDIUM,
                'expected_impact_label' => 'high',
                'recommended_next_step' => 'Inspect the page evidence and prepare approved response guidance if the risk is confirmed.',
            ],
            AlertRule::TRIGGER_COMPETITOR_CAMPAIGN_PAGE,
            AlertRule::TRIGGER_SERP_COMPETITOR_TOP_10_GAIN,
            AlertRule::TRIGGER_GEO_COMPETITOR_CITATION_GAIN,
            AlertRule::TRIGGER_GEO_COMPETITOR_DISPLACED_CLIENT,
            AlertRule::TRIGGER_COMPETITOR_PRESSURE_SPIKE => [
                'action_type' => 'respond_to_competitor_pressure',
                'category' => 'competitor_response',
                'title' => 'Review competitor pressure: '.$alert->title,
                'why_this_matters' => 'This Page Intelligence alert indicates competitor movement that may affect authority, visibility or positioning.',
                'expected_outcome' => 'Confirm the competitor evidence and decide whether to refresh positioning, publish counter-evidence or brief sales.',
                'what_argusly_will_do' => 'Argusly keeps competitor, page, SERP/GEO and signal evidence attached to the recommended action.',
                'what_requires_approval' => 'Approve any competitive response, claim, campaign or page update before execution.',
                'estimated_effort' => RecommendedAction::EFFORT_MEDIUM,
                'expected_impact_label' => 'high',
                'recommended_next_step' => 'Inspect competitor evidence and choose whether to refresh content, create a comparison asset or brief the team.',
            ],
            AlertRule::TRIGGER_HIGH_PR_VALUE_PAGE,
            AlertRule::TRIGGER_PR_VALUE_SPIKE,
            AlertRule::TRIGGER_HIGH_OPPORTUNITY_PAGE,
            AlertRule::TRIGGER_CAMPAIGN_PICKUP,
            AlertRule::TRIGGER_NEW_BRAND_PAGE => [
                'action_type' => 'amplify_page_opportunity',
                'category' => 'opportunity_activation',
                'title' => 'Activate opportunity: '.$alert->title,
                'why_this_matters' => 'This Page Intelligence alert indicates a page or mention with enough value to consider activation.',
                'expected_outcome' => 'Confirm the opportunity and decide whether to reuse the evidence in content, campaign, social or sales material.',
                'what_argusly_will_do' => 'Argusly keeps the page evidence, value metrics and related signals attached to the action.',
                'what_requires_approval' => 'Approve any campaign, publishing or outbound use of the evidence before execution.',
                'estimated_effort' => RecommendedAction::EFFORT_LOW,
                'expected_impact_label' => 'high',
                'recommended_next_step' => 'Inspect the page and decide whether to activate it as content, link it to an existing asset or use it in a campaign.',
            ],
            AlertRule::TRIGGER_SERP_TOP_10_GAIN,
            AlertRule::TRIGGER_SERP_TOP_10_LOSS,
            AlertRule::TRIGGER_SERP_FEATURED_SNIPPET_GAIN,
            AlertRule::TRIGGER_SERP_FEATURED_SNIPPET_LOSS,
            AlertRule::TRIGGER_GEO_CITATION_GAIN,
            AlertRule::TRIGGER_GEO_CITATION_LOSS => [
                'action_type' => 'review_visibility_movement',
                'category' => 'visibility_movement',
                'title' => 'Review visibility movement: '.$alert->title,
                'why_this_matters' => 'This Page Intelligence alert indicates search or answer-engine movement that may affect discoverability.',
                'expected_outcome' => 'Confirm the movement, identify affected pages and decide whether freshness, entity coverage or internal linking needs work.',
                'what_argusly_will_do' => 'Argusly keeps SERP/GEO observations and page evidence attached to the action.',
                'what_requires_approval' => 'Approve any page update, content refresh or publishing action before execution.',
                'estimated_effort' => RecommendedAction::EFFORT_MEDIUM,
                'expected_impact_label' => 'medium',
                'recommended_next_step' => 'Inspect the visibility evidence and decide whether to refresh or strengthen the affected page.',
            ],
            default => [
                'action_type' => 'review_page_alert',
                'category' => 'page_alert_review',
                'title' => 'Review '.$alert->title,
                'why_this_matters' => 'This Page Intelligence alert crossed a configured threshold and may require a timely response.',
                'expected_outcome' => 'Confirm the finding, decide whether to respond, and keep the related page evidence current.',
                'what_argusly_will_do' => 'Argusly keeps the alert linked to the monitored page, signal evidence, and scoring breakdown.',
                'what_requires_approval' => 'Approve any outreach, campaign, or content response before execution.',
                'estimated_effort' => RecommendedAction::EFFORT_MEDIUM,
                'expected_impact_label' => in_array($alert->severity, ['critical', 'high'], true) ? 'high' : 'medium',
                'recommended_next_step' => 'Inspect the page evidence and decide the next owner-approved action.',
            ],
        };
    }

    private function ctaUrl(PageAlert $alert): ?string
    {
        if (! $alert->monitored_page_id) {
            return null;
        }

        return route('app.page-intelligence.monitored-pages.show', ['monitoredPage' => $alert->monitored_page_id], false);
    }
}
