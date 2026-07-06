<?php

namespace App\Services\PageIntelligence\Alerts;

use App\Models\Notification;
use App\Models\PageAlert;
use App\Models\RecommendedAction;
use App\Services\Notifications\NotificationService;

class PageAlertNotificationMapper
{
    public function __construct(private readonly NotificationService $notifications)
    {
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

        return RecommendedAction::query()->updateOrCreate(
            ['source_signature' => $signature],
            [
                'workspace_id' => $alert->workspace_id,
                'organization_id' => $alert->organization_id,
                'source_type' => PageAlert::class,
                'source_id' => (string) $alert->id,
                'source_group' => RecommendedAction::SOURCE_AI_VISIBILITY,
                'action_type' => 'review_page_alert',
                'status' => RecommendedAction::STATUS_OPEN,
                'title' => 'Review '.$alert->title,
                'summary' => $alert->summary,
                'why_this_matters' => 'This Page Intelligence alert crossed a configured threshold and may require a timely response.',
                'expected_outcome' => 'Confirm the finding, decide whether to respond, and keep the related page evidence current.',
                'what_argusly_will_do' => 'Argusly keeps the alert linked to the monitored page, signal evidence, and scoring breakdown.',
                'what_requires_approval' => 'Approve any outreach, campaign, or content response before execution.',
                'estimated_effort' => RecommendedAction::EFFORT_MEDIUM,
                'priority_score' => $this->priority($alert->severity),
                'confidence_score' => max(50, min(100, (int) data_get($alert->metrics_json, 'confidence', 80))),
                'expected_impact_score' => max(50, min(100, (int) data_get($alert->metrics_json, 'impact', $this->priority($alert->severity)))),
                'priority_label' => $alert->severity === 'critical' ? 'critical' : 'high',
                'confidence_label' => 'high',
                'expected_impact_label' => in_array($alert->severity, ['critical', 'high'], true) ? 'high' : 'medium',
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
                ],
                'visible_at' => now(),
            ]
        );
    }

    private function ctaUrl(PageAlert $alert): ?string
    {
        if (! $alert->monitored_page_id) {
            return null;
        }

        return route('app.page-intelligence.monitored-pages.show', ['monitoredPage' => $alert->monitored_page_id], false);
    }
}
