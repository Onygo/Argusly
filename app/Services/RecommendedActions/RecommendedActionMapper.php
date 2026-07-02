<?php

namespace App\Services\RecommendedActions;

use App\Models\AgenticActionRun;
use App\Models\Campaign;
use App\Models\CompetitorContentOpportunity;
use App\Models\CompetitorTopicSignal;
use App\Models\ContentOpportunity;
use App\Models\ContentRecommendation;
use App\Models\LearningRecommendation;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\RecommendedAction;
use App\Models\SignalDetection;
use App\Models\SocialPostVariant;
use App\Models\Workspace;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalActionOwnershipResolver;
use App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionSignature;
use Illuminate\Database\Eloquent\Model;

class RecommendedActionMapper
{
    public function __construct(
        private readonly RecommendedActionScoring $scoring,
        private readonly ContentOpportunityRecommendedActionSignature $contentOpportunitySignature,
        private readonly ContentOpportunityCanonicalActionOwnershipResolver $contentOpportunityActionOwnership,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function map(Model $source): array
    {
        return match (true) {
            $source instanceof Opportunity => $this->opportunity($source),
            $source instanceof LearningRecommendation => $this->learning($source),
            $source instanceof SignalDetection => $this->aiVisibility($source),
            $source instanceof AgenticActionRun => $this->agenticAction($source),
            $source instanceof Campaign => $this->campaign($source),
            $source instanceof ContentRecommendation => $this->contentRecommendation($source),
            $source instanceof ContentOpportunity => $this->contentOpportunity($source),
            $source instanceof CompetitorContentOpportunity => $this->competitorContentOpportunity($source),
            $source instanceof CompetitorTopicSignal => $this->competitorTopicSignal($source),
            $source instanceof OpportunityExecutionPlan => $this->executionPlan($source),
            $source instanceof SocialPostVariant => $this->distribution($source),
            default => $this->generic($source),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function opportunity(Opportunity $opportunity): array
    {
        $action = $this->firstText($opportunity->recommended_actions) ?: 'Approve this opportunity and let Argusly prepare the execution path.';

        return $this->payload(
            source: $opportunity,
            workspace: $opportunity->workspace,
            sourceGroup: RecommendedAction::SOURCE_OPPORTUNITY,
            actionType: 'review_opportunity',
            status: $opportunity->actioned_at ? RecommendedAction::STATUS_COMPLETED : RecommendedAction::STATUS_OPEN,
            title: $opportunity->title,
            summary: $opportunity->summary,
            why: $opportunity->summary ?: 'This opportunity can improve growth outcomes for your connected site.',
            outcome: $opportunity->topic ? 'A concrete growth action around '.$opportunity->topic.'.' : 'A prioritized growth action ready for execution.',
            willDo: 'Argusly will turn the opportunity into an execution recommendation, content brief, campaign, or growth program.',
            approval: 'You approve, dismiss, or ask for changes before execution begins.',
            effort: RecommendedAction::EFFORT_LOW,
            baseScore: $opportunity->priority_score,
            confidenceScore: $opportunity->confidence_score,
            impactScore: $opportunity->impact_score,
            cta: ['Review action', route('app.opportunities.show', $opportunity)],
            context: ['approval_required' => ! $opportunity->actioned_at],
            metadata: ['recommended_action' => $action],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function learning(LearningRecommendation $recommendation): array
    {
        $action = $this->firstText($recommendation->recommended_actions) ?: 'Apply this learning to the next content or campaign action.';

        return $this->payload(
            source: $recommendation,
            workspace: $recommendation->workspace,
            sourceGroup: RecommendedAction::SOURCE_LEARNING,
            actionType: 'apply_learning',
            status: $recommendation->actioned_at ? RecommendedAction::STATUS_COMPLETED : RecommendedAction::STATUS_OPEN,
            title: $recommendation->title,
            summary: $recommendation->summary,
            why: $recommendation->summary ?: 'Recent performance created a learning that can improve the next action.',
            outcome: 'Future content or campaigns should use the winning pattern from this learning.',
            willDo: 'Argusly will apply the learning to content planning, campaign planning, or distribution recommendations.',
            approval: 'You choose whether this learning should be applied now.',
            effort: RecommendedAction::EFFORT_LOW,
            baseScore: $recommendation->priority_score,
            confidenceScore: $recommendation->confidence_score,
            impactScore: (float) data_get($recommendation->expected_impact, 'score', $recommendation->priority_score),
            cta: ['Review learning', route('app.agentic-marketing.learning.index', ['workspace_id' => $recommendation->workspace_id])],
            metadata: ['recommended_action' => $action],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function aiVisibility(SignalDetection $detection): array
    {
        return $this->payload(
            source: $detection,
            workspace: $detection->workspace,
            sourceGroup: RecommendedAction::SOURCE_AI_VISIBILITY,
            actionType: 'review_visibility_gap',
            status: $detection->resolved_at ? RecommendedAction::STATUS_COMPLETED : RecommendedAction::STATUS_OPEN,
            title: $detection->title,
            summary: $detection->summary,
            why: $detection->summary ?: 'AI visibility changed in a way that may affect demand capture.',
            outcome: 'A visibility gap can become a content, brand, or distribution action.',
            willDo: 'Argusly will promote the signal into an opportunity when you approve it.',
            approval: 'You decide whether this signal is worth acting on.',
            effort: RecommendedAction::EFFORT_LOW,
            baseScore: $detection->priority_score,
            confidenceScore: $detection->confidence_score,
            impactScore: $detection->opportunity_score ?: $detection->impact_score,
            cta: ['Review signal', route('app.opportunities.candidates.show', $detection)],
            context: ['approval_required' => ! $detection->resolved_at],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function agenticAction(AgenticActionRun $run): array
    {
        $status = (string) $run->status;
        $needsApproval = in_array($status, [AgenticActionRun::STATUS_PROPOSED, AgenticActionRun::STATUS_APPROVAL_REQUIRED], true);

        return $this->payload(
            source: $run,
            workspace: $run->workspace,
            sourceGroup: RecommendedAction::SOURCE_AGENTIC_MARKETING,
            actionType: (string) $run->action_type,
            status: $status === AgenticActionRun::STATUS_COMPLETED ? RecommendedAction::STATUS_COMPLETED : RecommendedAction::STATUS_OPEN,
            title: str((string) $run->action_type)->replace('_', ' ')->headline()->toString(),
            summary: $run->reason,
            why: $run->reason ?: 'Argusly identified an agentic marketing action that can move work forward.',
            outcome: 'The action moves from recommendation to execution under your governance rules.',
            willDo: 'Argusly will run the action, track the result, and keep the audit trail.',
            approval: $needsApproval ? 'You must approve this action before Argusly runs it.' : 'No additional approval is required under the current policy.',
            effort: RecommendedAction::EFFORT_AUTOMATED,
            baseScore: data_get($run->input_snapshot, 'payload.priority_score', data_get($run->policy_snapshot, 'priority_score', 60)),
            confidenceScore: data_get($run->policy_snapshot, 'confidence_score', 65),
            impactScore: data_get($run->input_snapshot, 'payload.impact_score', 60),
            cta: ['Open approval', route('app.agentic-marketing.approvals.index', ['workspace_id' => $run->workspace_id])],
            context: ['approval_required' => $needsApproval, 'blocked' => $status === AgenticActionRun::STATUS_BLOCKED],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function campaign(Campaign $campaign): array
    {
        return $this->payload(
            source: $campaign,
            workspace: $campaign->workspace,
            sourceGroup: RecommendedAction::SOURCE_CAMPAIGN_PLANNING,
            actionType: 'approve_campaign_plan',
            status: $campaign->approved_at ? RecommendedAction::STATUS_COMPLETED : RecommendedAction::STATUS_OPEN,
            title: 'Review campaign plan: '.$campaign->name,
            summary: $campaign->objective,
            why: $campaign->objective ?: 'A campaign plan is ready to connect content, channels, and outcomes.',
            outcome: 'Approved campaign assets can move into creation and distribution.',
            willDo: 'Argusly will generate or coordinate campaign assets and distribution steps.',
            approval: 'You approve the campaign plan and any gated assets before launch.',
            effort: RecommendedAction::EFFORT_MEDIUM,
            baseScore: data_get($campaign->metadata, 'priority_score', 60),
            confidenceScore: data_get($campaign->ai_planning_context, 'confidence_score', 65),
            impactScore: data_get($campaign->metadata, 'expected_impact_score', 70),
            cta: ['Open planner', route('app.agentic-marketing.campaign-planner.index', ['campaign' => $campaign->id])],
            context: ['approval_required' => ! $campaign->approved_at],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function contentRecommendation(ContentRecommendation $recommendation): array
    {
        $content = $recommendation->content;

        return $this->payload(
            source: $recommendation,
            workspace: $content?->workspace,
            sourceGroup: RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
            actionType: (string) $recommendation->type,
            status: RecommendedAction::STATUS_OPEN,
            title: (string) data_get($recommendation->payload, 'title', str((string) $recommendation->type)->replace('_', ' ')->headline()),
            summary: (string) data_get($recommendation->payload, 'summary', ''),
            why: (string) data_get($recommendation->payload, 'why_this_matters', data_get($recommendation->payload, 'summary', 'This content can be improved.')),
            outcome: (string) data_get($recommendation->payload, 'expected_outcome', 'The content should become clearer, stronger, or more useful.'),
            willDo: (string) data_get($recommendation->payload, 'what_argusly_will_do', 'Argusly will prepare the suggested content improvement.'),
            approval: (string) data_get($recommendation->payload, 'what_requires_approval', 'You approve the content change before publishing.'),
            effort: (string) data_get($recommendation->payload, 'estimated_effort', RecommendedAction::EFFORT_MEDIUM),
            baseScore: data_get($recommendation->payload, 'priority_score', $recommendation->priority),
            confidenceScore: data_get($recommendation->payload, 'confidence_score', 65),
            impactScore: data_get($recommendation->payload, 'expected_impact_score', 60),
            cta: $content ? ['Open content', route('app.content.show', $content)] : null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function contentOpportunity(ContentOpportunity $opportunity): array
    {
        $ownership = $this->contentOpportunityActionOwnership->resolve(
            legacy: $opportunity,
            featureEnabled: (bool) config('features.mos_canonical_content_opportunity_action_ownership', false),
        );
        $canonicalActive = $ownership['ownership_status'] === ContentOpportunityCanonicalActionOwnershipResolver::STATUS_CANONICAL_ACTIVE;

        return $this->payload(
            source: $opportunity,
            workspace: $opportunity->workspace,
            sourceGroup: RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
            actionType: 'prepare_content_opportunity',
            status: (string) $opportunity->status === ContentOpportunity::STATUS_OPEN ? RecommendedAction::STATUS_OPEN : RecommendedAction::STATUS_COMPLETED,
            title: $opportunity->title,
            summary: $opportunity->reasoning,
            why: $opportunity->why_this_matters ?: $opportunity->reasoning ?: 'Content intelligence found a growth opportunity.',
            outcome: $opportunity->expected_impact ?: 'A prepared content action can improve demand capture and AI visibility.',
            willDo: 'Argusly will prepare the content angle, internal links, schema suggestions, and next production step.',
            approval: 'You approve the content opportunity before Argusly creates or updates assets.',
            effort: RecommendedAction::EFFORT_MEDIUM,
            baseScore: $opportunity->priority_score,
            confidenceScore: $opportunity->confidence_score,
            impactScore: $opportunity->business_value_score ?: $opportunity->priority_score,
            cta: $canonicalActive
                ? ['Review action', (string) $ownership['cta_route']]
                : ['Open content opportunities', route('app.agentic-marketing.content-opportunities.index', ['workspace_id' => $opportunity->workspace_id])],
            context: ['approval_required' => true],
            metadata: array_filter([
                'recommended_action' => $opportunity->angle ?: 'Prepare this content opportunity for execution.',
                'canonical_action_ownership' => $canonicalActive ? [
                    'ownership_status' => $ownership['ownership_status'],
                    'canonical_owner_id' => $ownership['canonical_owner_id'],
                    'legacy_source_id' => $ownership['legacy_source_id'],
                    'display_action_id' => $ownership['display_action_id'],
                    'primary_recommended_action_id' => $ownership['primary_recommended_action_id'],
                    'duplicate_recommended_action_ids' => $ownership['duplicate_recommended_action_ids'],
                    'source_link' => $ownership['source_link'],
                    'legacy_source_link' => $ownership['legacy_source_link'],
                    'fallback_route' => $ownership['fallback_route'],
                    'duplicate_metadata_status' => $ownership['duplicate_metadata_status'],
                ] : null,
            ]),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function competitorContentOpportunity(CompetitorContentOpportunity $opportunity): array
    {
        return $this->payload(
            source: $opportunity,
            workspace: $opportunity->workspace,
            sourceGroup: RecommendedAction::SOURCE_COMPETITOR_INTELLIGENCE,
            actionType: 'respond_to_competitor_gap',
            status: in_array((string) $opportunity->status, ['dismissed', 'archived', 'completed'], true) ? RecommendedAction::STATUS_COMPLETED : RecommendedAction::STATUS_OPEN,
            title: $opportunity->title,
            summary: $opportunity->reason,
            why: $opportunity->reason ?: 'Competitor intelligence found a content gap or market opening.',
            outcome: $opportunity->recommended_format ? 'A '.$opportunity->recommended_format.' that counters competitor coverage.' : 'A prepared response to competitor coverage.',
            willDo: 'Argusly will prepare the angle, evidence, format recommendation, and content execution path.',
            approval: 'You approve the competitor response before Argusly creates assets.',
            effort: $this->effortFromNumber($opportunity->effort_score),
            baseScore: $opportunity->priority_score,
            confidenceScore: $opportunity->confidence_score,
            impactScore: $opportunity->impact_score,
            cta: $opportunity->site ? ['Open competitor intelligence', route('app.sites.competitor-intelligence.index', $opportunity->site)] : null,
            context: ['approval_required' => true, 'urgent' => true],
            metadata: [
                'recommended_action' => $opportunity->attackable_angle ?: 'Prepare a content response to this competitor opportunity.',
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function competitorTopicSignal(CompetitorTopicSignal $signal): array
    {
        return $this->payload(
            source: $signal,
            workspace: $signal->workspace,
            sourceGroup: RecommendedAction::SOURCE_COMPETITOR_INTELLIGENCE,
            actionType: 'close_competitor_topic_gap',
            status: RecommendedAction::STATUS_OPEN,
            title: 'Close competitor topic gap: '.$signal->topic,
            summary: 'Competitors have '.$signal->competitor_content_count.' related content item'.((int) $signal->competitor_content_count === 1 ? '' : 's').'.',
            why: 'Competitor topic overlap indicates missing or weak coverage.',
            outcome: 'Argusly can prepare content that improves coverage for '.$signal->topic.'.',
            willDo: 'Argusly will prepare a topic brief, evidence summary, and recommended format.',
            approval: 'You approve the topic before Argusly turns it into content.',
            effort: RecommendedAction::EFFORT_MEDIUM,
            baseScore: $signal->opportunity_score,
            confidenceScore: $signal->overlap_score,
            impactScore: $signal->opportunity_score,
            cta: $signal->site ? ['Open competitor intelligence', route('app.sites.competitor-intelligence.index', $signal->site)] : null,
            context: ['approval_required' => true, 'urgent' => true],
            metadata: [
                'recommended_action' => 'Prepare coverage for '.$signal->topic.'.',
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function executionPlan(OpportunityExecutionPlan $plan): array
    {
        $needsApproval = in_array((string) $plan->status, [OpportunityExecutionPlan::STATUS_DRAFT, OpportunityExecutionPlan::STATUS_REVIEWING], true);

        return $this->payload(
            source: $plan,
            workspace: $plan->workspace,
            sourceGroup: RecommendedAction::SOURCE_OPPORTUNITY,
            actionType: 'approve_execution_recommendation',
            status: (string) $plan->status === OpportunityExecutionPlan::STATUS_PLANNED ? RecommendedAction::STATUS_COMPLETED : RecommendedAction::STATUS_OPEN,
            title: $plan->title,
            summary: $plan->summary ?: $plan->objective,
            why: $plan->objective ?: $plan->summary ?: 'A recommended execution path is ready.',
            outcome: 'An approved execution path can become content, distribution, or a growth program.',
            willDo: 'Argusly will turn the plan into the next execution artifact.',
            approval: $needsApproval ? 'You approve or request changes before execution starts.' : 'This recommendation is already approved.',
            effort: $this->effortFromNumber($plan->estimated_effort),
            baseScore: $plan->priority_score,
            confidenceScore: 70,
            impactScore: $plan->expected_impact,
            cta: ['Open recommendation', route('app.opportunities.execution-recommendations.show', $plan)],
            context: ['approval_required' => $needsApproval],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function distribution(SocialPostVariant $variant): array
    {
        return $this->payload(
            source: $variant,
            workspace: $variant->workspace,
            sourceGroup: RecommendedAction::SOURCE_DISTRIBUTION,
            actionType: 'approve_distribution_variant',
            status: $variant->approved_at ? RecommendedAction::STATUS_COMPLETED : RecommendedAction::STATUS_OPEN,
            title: 'Review distribution post',
            summary: $variant->hook ?: str((string) $variant->body)->limit(140)->toString(),
            why: 'A distribution asset is ready to help existing content reach more buyers.',
            outcome: 'The selected variant can be scheduled or published to the connected channel.',
            willDo: 'Argusly will schedule or publish the approved variant through the distribution workflow.',
            approval: 'You approve the final copy before it is scheduled.',
            effort: RecommendedAction::EFFORT_LOW,
            baseScore: $variant->score ?: $variant->quality_score,
            confidenceScore: $variant->quality_score ?: 60,
            impactScore: $variant->score ?: 60,
            cta: ['Open distribution', route('app.agentic-marketing.distribution.index', ['workspace_id' => $variant->workspace_id])],
            context: ['approval_required' => ! $variant->approved_at],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function generic(Model $source): array
    {
        $workspace = method_exists($source, 'workspace') ? $source->workspace : null;

        return $this->payload(
            source: $source,
            workspace: $workspace instanceof Workspace ? $workspace : null,
            sourceGroup: 'general',
            actionType: str(class_basename($source))->snake()->toString(),
            status: RecommendedAction::STATUS_OPEN,
            title: class_basename($source),
            summary: null,
            why: 'Argusly found an item that can be represented as a recommended action.',
            outcome: 'The item can be reviewed in one action inbox.',
            willDo: 'Argusly will keep the source linked and visible.',
            approval: 'Review the source item before execution.',
            effort: RecommendedAction::EFFORT_MEDIUM,
            baseScore: 50,
            confidenceScore: 50,
            impactScore: 50,
        );
    }

    /**
     * @param  array{0:string,1:string}|null  $cta
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function payload(
        Model $source,
        ?Workspace $workspace,
        string $sourceGroup,
        string $actionType,
        string $status,
        string $title,
        ?string $summary,
        string $why,
        string $outcome,
        string $willDo,
        string $approval,
        string $effort,
        mixed $baseScore,
        mixed $confidenceScore,
        mixed $impactScore,
        ?array $cta = null,
        array $context = [],
        array $metadata = [],
    ): array {
        $normalizedBaseScore = $this->scoreValue($baseScore);
        $confidence = $this->scoring->confidence($this->scoreValue($confidenceScore), $metadata);
        $impact = $this->scoring->expectedImpact($this->scoreValue($impactScore), $outcome);
        $priority = $this->scoring->priority($normalizedBaseScore, $confidence, $impact, $context);

        return array_filter([
            'workspace_id' => $workspace?->id,
            'organization_id' => $workspace?->organization_id,
            'source_type' => $source::class,
            'source_id' => (string) $source->getKey(),
            'source_signature' => $this->signature($source, $workspace, $sourceGroup, $actionType),
            'source_group' => $sourceGroup,
            'action_type' => $actionType,
            'status' => $status,
            'title' => trim($title) !== '' ? $title : class_basename($source),
            'summary' => $summary,
            'why_this_matters' => $why,
            'expected_outcome' => $outcome,
            'what_argusly_will_do' => $willDo,
            'what_requires_approval' => $approval,
            'estimated_effort' => in_array($effort, [RecommendedAction::EFFORT_LOW, RecommendedAction::EFFORT_MEDIUM, RecommendedAction::EFFORT_HIGH, RecommendedAction::EFFORT_AUTOMATED], true) ? $effort : RecommendedAction::EFFORT_MEDIUM,
            'priority_score' => $priority,
            'confidence_score' => $confidence,
            'expected_impact_score' => $impact,
            'priority_label' => $this->scoring->label($priority),
            'confidence_label' => $this->scoring->label($confidence),
            'expected_impact_label' => $this->scoring->label($impact),
            'primary_cta_label' => $cta[0] ?? null,
            'primary_cta_url' => $cta[1] ?? null,
            'visible_at' => now(),
            'metadata' => array_filter(array_merge($metadata, [
                'base_score' => $normalizedBaseScore,
                'context' => $context,
            ])),
        ], fn ($value): bool => $value !== null);
    }

    private function signature(Model $source, ?Workspace $workspace, string $sourceGroup, string $actionType): string
    {
        return $this->contentOpportunitySignature->signature($source, $workspace, $sourceGroup, $actionType);
    }

    private function firstText(mixed $values): ?string
    {
        if (is_string($values)) {
            return trim($values) !== '' ? trim($values) : null;
        }

        if (! is_array($values)) {
            return null;
        }

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                $candidate = trim((string) ($value['title'] ?? $value['description'] ?? $value['action'] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function scoreValue(mixed $score): float|int|null
    {
        if (is_int($score) || is_float($score)) {
            return $score;
        }

        if (! is_string($score)) {
            return null;
        }

        $score = trim($score);

        if ($score === '') {
            return null;
        }

        if (is_numeric($score)) {
            return str_contains($score, '.') ? (float) $score : (int) $score;
        }

        return match (strtolower($score)) {
            'critical' => 90,
            'high' => 80,
            'medium' => 55,
            'low' => 30,
            default => null,
        };
    }

    private function effortFromNumber(mixed $effort): string
    {
        if (is_string($effort) && in_array($effort, [RecommendedAction::EFFORT_LOW, RecommendedAction::EFFORT_MEDIUM, RecommendedAction::EFFORT_HIGH, RecommendedAction::EFFORT_AUTOMATED], true)) {
            return $effort;
        }

        $effort = $this->scoreValue($effort);

        return match (true) {
            $effort === null => RecommendedAction::EFFORT_MEDIUM,
            $effort <= 35 => RecommendedAction::EFFORT_LOW,
            $effort >= 75 => RecommendedAction::EFFORT_HIGH,
            default => RecommendedAction::EFFORT_MEDIUM,
        };
    }
}
