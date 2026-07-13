<?php

namespace App\Services\SignalIntelligence;

use App\Enums\OpportunityCategory;
use App\Enums\SignalSeverity;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use Illuminate\Support\Arr;

class SignalDetectionImpactAnalyzer
{
    /**
     * @param array<int,string> $eventIds
     * @return array<string,mixed>
     */
    public function analyze(SignalDetection $detection, ?OpportunityCategory $opportunityCategory = null, array $eventIds = []): array
    {
        $category = (string) $detection->category;
        $type = (string) $detection->type;
        $priority = (float) ($detection->priority_score ?? 0);
        $impact = (float) ($detection->impact_score ?? 0);
        $urgency = (float) ($detection->urgency_score ?? 0);
        $risk = (float) ($detection->risk_score ?? 0);
        $opportunity = (float) ($detection->opportunity_score ?? 0);
        $confidence = (float) ($detection->confidence_score ?? 0);

        return $this->prune([
            'schema_version' => 'signal_impact.v1',
            'why_this_matters' => $this->whyThisMatters($category, $type),
            'business_impact' => [
                'summary' => $this->businessImpactSummary($category, $impact, $risk, $opportunity),
                'priority_score' => $priority,
                'impact_score' => $impact,
                'risk_score' => $risk,
                'opportunity_score' => $opportunity,
                'opportunity_category' => $opportunityCategory?->value,
            ],
            'affected_scope' => [
                'workspace_id' => (string) $detection->workspace_id,
                'client_site_id' => $detection->client_site_id ? (string) $detection->client_site_id : null,
                'topic' => $detection->primary_topic,
                'entity' => $detection->primary_entity,
                'signal_category' => $category,
                'signal_type' => $type,
                'linked_signal_event_ids' => $eventIds,
                'event_count' => count($eventIds) ?: null,
            ],
            'urgency' => [
                'score' => $urgency,
                'label' => $this->scoreLabel($urgency),
                'reason' => $this->urgencyReason($detection, $urgency),
            ],
            'confidence' => [
                'score' => $confidence,
                'label' => $this->scoreLabel($confidence),
            ],
            'recommended_next_step' => $this->recommendedNextStep($category, $type),
            'suggested_actions' => $this->suggestedActions($category, $type),
            'approval_required' => true,
        ]);
    }

    /**
     * @param array<int,string> $eventIds
     * @return array<string,mixed>
     */
    public function evidencePackage(SignalDetection $detection, array $eventIds = []): array
    {
        $events = $detection->relationLoaded('events')
            ? $detection->events
            : collect();

        return $this->prune([
            'schema_version' => 'signal_evidence_package.v1',
            'title' => $detection->title,
            'summary' => $detection->summary,
            'signal_detection_id' => (string) $detection->id,
            'linked_signal_event_ids' => $eventIds,
            'score_breakdown' => $detection->score_breakdown ?? [],
            'evidence_summary' => $detection->evidence_summary ?? [],
            'recommended_actions' => $detection->recommended_actions ?? [],
            'events' => $events
                ->take(8)
                ->map(fn (SignalEvent $event): array => $this->eventEvidence($event))
                ->values()
                ->all(),
        ]);
    }

    private function whyThisMatters(string $category, string $type): string
    {
        return match ($category) {
            SignalDetection::CATEGORY_COMPETITOR_MONITORING => 'This signal indicates competitor movement that may affect authority, visibility or positioning.',
            SignalDetection::CATEGORY_TREND_DETECTION => 'This signal points to topic movement that may create a timely content, campaign or sales enablement opening.',
            SignalDetection::CATEGORY_RISK_DETECTION => 'This signal indicates a risk pattern that should be reviewed before it affects trust, demand or visibility.',
            SignalDetection::CATEGORY_OPPORTUNITY_DETECTION => 'This signal indicates an opportunity candidate that can be promoted into planned marketing work.',
            SignalDetection::CATEGORY_BRAND_MONITORING => str_contains($type, 'negative')
                ? 'This signal may affect brand trust and should be reviewed with approved response guidance.'
                : 'This signal indicates brand visibility movement that may be worth amplifying or strengthening.',
            default => 'This signal crossed a configured threshold and should be reviewed with its evidence before action.',
        };
    }

    private function businessImpactSummary(string $category, float $impact, float $risk, float $opportunity): string
    {
        return match ($category) {
            SignalDetection::CATEGORY_COMPETITOR_MONITORING => sprintf('Competitor pressure score %.1f with impact %.1f; review positioning, proof points and response options.', max($risk, $opportunity), $impact),
            SignalDetection::CATEGORY_TREND_DETECTION => sprintf('Trend opportunity score %.1f with impact %.1f; consider timely content or campaign activation.', $opportunity, $impact),
            SignalDetection::CATEGORY_RISK_DETECTION => sprintf('Risk score %.1f with impact %.1f; confirm evidence and decide whether mitigation is needed.', $risk, $impact),
            SignalDetection::CATEGORY_BRAND_MONITORING => sprintf('Brand visibility impact %.1f with opportunity score %.1f; decide whether to amplify or improve coverage.', $impact, $opportunity),
            default => sprintf('Signal impact %.1f with opportunity score %.1f; review evidence and choose the next owner-approved action.', $impact, $opportunity),
        };
    }

    private function urgencyReason(SignalDetection $detection, float $urgency): string
    {
        $severity = $detection->severity instanceof SignalSeverity
            ? $detection->severity->value
            : (string) $detection->severity;

        if (in_array($severity, ['critical', 'high'], true)) {
            return 'The detection is marked '.$severity.' and should be reviewed promptly.';
        }

        return $urgency >= 70
            ? 'The urgency score is high enough to prioritize review.'
            : 'The urgency score suggests this can be reviewed in the normal planning flow.';
    }

    private function recommendedNextStep(string $category, string $type): string
    {
        return match ($category) {
            SignalDetection::CATEGORY_COMPETITOR_MONITORING => 'Inspect competitor evidence and decide whether to refresh positioning, create a comparison asset or brief sales.',
            SignalDetection::CATEGORY_TREND_DETECTION => 'Validate the trend evidence and decide whether to create or refresh content while the topic is active.',
            SignalDetection::CATEGORY_RISK_DETECTION => 'Review the evidence, confirm the risk, and prepare an approved response or mitigation plan if needed.',
            SignalDetection::CATEGORY_OPPORTUNITY_DETECTION => 'Promote the signal into Opportunity Intelligence and attach it to content, campaign or social planning.',
            SignalDetection::CATEGORY_BRAND_MONITORING => str_contains($type, 'visibility')
                ? 'Review the visibility evidence and decide whether to amplify proof points or improve owned coverage.'
                : 'Review the brand evidence and decide whether a content or communication response is needed.',
            default => 'Review the signal evidence and choose the next owner-approved action.',
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function suggestedActions(string $category, string $type): array
    {
        return match ($category) {
            SignalDetection::CATEGORY_COMPETITOR_MONITORING => [
                $this->action('refresh_positioning', 'Refresh positioning or proof points', 'content'),
                $this->action('prepare_sales_enablement', 'Prepare sales enablement note', 'sales'),
                $this->action('create_comparison_asset', 'Create or update comparison asset', 'campaign'),
            ],
            SignalDetection::CATEGORY_TREND_DETECTION => [
                $this->action('create_trend_content', 'Create timely trend content', 'content'),
                $this->action('refresh_existing_content', 'Refresh existing related page', 'content'),
                $this->action('draft_social_post', 'Draft social activation', 'social'),
            ],
            SignalDetection::CATEGORY_RISK_DETECTION => [
                $this->action('prepare_response_guidance', 'Prepare approved response guidance', 'governance'),
                $this->action('refresh_trust_content', 'Refresh trust or proof content', 'content'),
                $this->action('monitor_follow_up', 'Monitor follow-up signals', 'monitoring'),
            ],
            SignalDetection::CATEGORY_OPPORTUNITY_DETECTION => [
                $this->action('create_opportunity_brief', 'Create opportunity brief', 'planning'),
                $this->action('attach_to_campaign', 'Attach to campaign planning', 'campaign'),
                $this->action('prepare_content_variant', 'Prepare content variant', 'content'),
            ],
            SignalDetection::CATEGORY_BRAND_MONITORING => [
                $this->action('amplify_brand_proof', 'Amplify brand proof points', 'content'),
                $this->action('improve_owned_coverage', 'Improve owned coverage', 'content'),
                $this->action('draft_social_post', 'Draft social activation', 'social'),
            ],
            default => [
                $this->action('review_signal', 'Review signal evidence', 'planning'),
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function action(string $type, string $label, string $route): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'route' => $route,
            'requires_approval' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function eventEvidence(SignalEvent $event): array
    {
        return $this->prune([
            'id' => (string) $event->id,
            'observed_at' => $event->observed_at?->toIso8601String(),
            'type' => $event->type?->value ?? $event->type,
            'topic' => $event->topic,
            'entity_name' => $event->entity_name,
            'source' => $event->signalSource?->name,
            'evidence' => Arr::wrap($event->evidence),
            'metrics' => $event->metrics ?? [],
        ]);
    }

    private function scoreLabel(float $score): string
    {
        return match (true) {
            $score >= 85 => 'critical',
            $score >= 70 => 'high',
            $score >= 45 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function prune(array $payload): array
    {
        return collect($payload)
            ->map(function (mixed $value): mixed {
                if (is_array($value)) {
                    return $this->prune($value);
                }

                return $value;
            })
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->all();
    }
}
