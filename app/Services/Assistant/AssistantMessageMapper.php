<?php

namespace App\Services\Assistant;

use App\Models\AgenticActionRun;
use App\Models\AssistantFeedItem;
use App\Models\Content;
use App\Models\ContentPackage;
use App\Models\ContentRecommendation;
use App\Models\DraftRecommendation;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\LearningRecommendation;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\RecommendedAction;
use App\Models\SignalDetection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

class AssistantMessageMapper
{
    public function __construct(private readonly AssistantPrioritySystem $prioritySystem)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function map(Model $source): array
    {
        return match (true) {
            $source instanceof Opportunity => $this->opportunity($source),
            $source instanceof LearningRecommendation => $this->learning($source),
            $source instanceof OpportunityExecutionPlan => $this->executionPlan($source),
            $source instanceof AgenticActionRun => $this->actionRun($source),
            $source instanceof SignalDetection => $this->signalDetection($source),
            $source instanceof ContentRecommendation => $this->contentRecommendation($source),
            $source instanceof DraftRecommendation => $this->draftRecommendation($source),
            $source instanceof RecommendedAction => $this->recommendedAction($source),
            $source instanceof GrowthAutopilotQueueItem => $this->growthAutopilotQueueItem($source),
            $source instanceof ContentPackage => $this->contentPackage($source),
            $source instanceof Content => $this->content($source),
            default => $this->generic($source),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function contentPackage(ContentPackage $package): array
    {
        return $this->payload(
            source: $package,
            workspace: $package->workspace,
            category: AssistantFeedItem::CATEGORY_CONTENT_ACTION,
            state: AssistantFeedItem::STATE_PREPARED,
            title: $package->title,
            summary: $package->opportunity_summary,
            baseScore: 80,
            sections: [
                'i_found' => $package->opportunity_summary ?: 'A content package is ready for review.',
                'i_recommend' => $package->recommended_action ?: 'Review the prepared content package.',
                'i_prepared' => 'I prepared a brief, draft, LinkedIn variant, CTA recommendation, internal linking suggestions, and publishing checklist.',
                'i_completed' => null,
                'i_need_your_input' => 'Review the draft and approve the package for publishing.',
            ],
            cta: $package->draft_id ? ['Review draft', route('app.drafts.show', $package->draft_id)] : ['Open content pipeline', route('app.content.pipeline.index')],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function growthAutopilotQueueItem(GrowthAutopilotQueueItem $item): array
    {
        $completed = $item->status === GrowthAutopilotQueueItem::STATUS_COMPLETED;
        $needsInput = $item->approval_required && ! in_array($item->status, [
            GrowthAutopilotQueueItem::STATUS_APPROVED,
            GrowthAutopilotQueueItem::STATUS_PREPARED,
            GrowthAutopilotQueueItem::STATUS_COMPLETED,
        ], true);

        return $this->payload(
            source: $item,
            workspace: $item->workspace,
            category: AssistantFeedItem::CATEGORY_RECOMMENDATION,
            state: $completed
                ? AssistantFeedItem::STATE_COMPLETED
                : ($needsInput ? AssistantFeedItem::STATE_NEEDS_INPUT : AssistantFeedItem::STATE_PREPARED),
            title: $item->opportunity,
            summary: $item->expected_impact,
            baseScore: $item->priority_score,
            sections: [
                'i_found' => (string) data_get($item->metadata, 'why_this_matters', $item->expected_impact),
                'i_recommend' => $item->recommended_action,
                'i_prepared' => 'I prepared '.count($item->prepared_assets ?? []).' asset'.(count($item->prepared_assets ?? []) === 1 ? '' : 's').' for this growth action.',
                'i_completed' => $completed ? 'This autopilot queue item has been completed.' : null,
                'i_need_your_input' => $needsInput ? $item->approval_requirement : null,
            ],
            cta: $item->approval_cta_url ? [$item->approval_cta_label ?: 'Review action', $item->approval_cta_url] : ['Open actions inbox', route('app.recommended-actions.index')],
            context: ['urgent' => in_array($item->priority_label, ['critical', 'high'], true)],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendedAction(RecommendedAction $action): array
    {
        $completed = $action->status === RecommendedAction::STATUS_COMPLETED;

        return $this->payload(
            source: $action,
            workspace: $action->workspace,
            category: match ((string) $action->source_group) {
                RecommendedAction::SOURCE_OPPORTUNITY,
                RecommendedAction::SOURCE_AI_VISIBILITY => AssistantFeedItem::CATEGORY_OPPORTUNITY,
                RecommendedAction::SOURCE_LEARNING => AssistantFeedItem::CATEGORY_LEARNING,
                RecommendedAction::SOURCE_AGENTIC_MARKETING,
                RecommendedAction::SOURCE_DISTRIBUTION => AssistantFeedItem::CATEGORY_EXECUTION,
                default => AssistantFeedItem::CATEGORY_RECOMMENDATION,
            },
            state: $completed ? AssistantFeedItem::STATE_COMPLETED : AssistantFeedItem::STATE_NEEDS_INPUT,
            title: $action->title,
            summary: $action->summary,
            baseScore: $action->priority_score,
            sections: [
                'i_found' => $action->why_this_matters,
                'i_recommend' => $action->expected_outcome,
                'i_prepared' => $action->what_argusly_will_do,
                'i_completed' => $completed ? 'This recommended action has been completed.' : null,
                'i_need_your_input' => $completed ? null : $action->what_requires_approval,
            ],
            cta: $action->primary_cta_url ? [$action->primary_cta_label ?: 'Open action', $action->primary_cta_url] : ['Open actions inbox', route('app.recommended-actions.index')],
            context: ['urgent' => in_array($action->priority_label, ['critical', 'high'], true)],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function opportunity(Opportunity $opportunity): array
    {
        $state = $opportunity->actioned_at
            ? AssistantFeedItem::STATE_COMPLETED
            : AssistantFeedItem::STATE_NEEDS_INPUT;
        $action = $this->firstText($opportunity->recommended_actions) ?: 'Approve this opportunity or ask Argusly to prepare the next action.';

        return $this->payload(
            source: $opportunity,
            workspace: $opportunity->workspace,
            category: AssistantFeedItem::CATEGORY_OPPORTUNITY,
            state: $state,
            title: $opportunity->title,
            summary: $opportunity->summary,
            baseScore: $opportunity->impact_score ?: $opportunity->priority_score,
            sections: [
                'i_found' => $opportunity->summary ?: 'A growth opportunity is ready for review.',
                'i_recommend' => $action,
                'i_prepared' => 'I prepared the opportunity context, impact estimate, and recommended next step.',
                'i_completed' => $opportunity->actioned_at ? 'This opportunity has already been actioned.' : null,
                'i_need_your_input' => $opportunity->actioned_at ? null : 'Approve, dismiss, or ask Argusly to prepare the execution path.',
            ],
            cta: ['Review opportunity', route('app.opportunities.show', $opportunity)],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function learning(LearningRecommendation $recommendation): array
    {
        $action = $this->firstText($recommendation->recommended_actions) ?: 'Review this recommendation and turn it into a follow-up action.';

        return $this->payload(
            source: $recommendation,
            workspace: $recommendation->workspace,
            category: AssistantFeedItem::CATEGORY_LEARNING,
            state: $recommendation->actioned_at ? AssistantFeedItem::STATE_COMPLETED : AssistantFeedItem::STATE_RECOMMEND,
            title: $recommendation->title,
            summary: $recommendation->summary,
            baseScore: $recommendation->priority_score,
            sections: [
                'i_found' => $recommendation->summary ?: 'A learning insight is available from recent performance.',
                'i_recommend' => $action,
                'i_prepared' => 'I prepared the evidence, expected impact, and action checklist.',
                'i_completed' => $recommendation->actioned_at ? 'This learning recommendation has been actioned.' : null,
                'i_need_your_input' => $recommendation->actioned_at ? null : 'Choose whether to apply this learning to content, campaigns, or distribution.',
            ],
            cta: ['Review learning', route('app.agentic-marketing.learning.index', ['workspace_id' => $recommendation->workspace_id])],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function executionPlan(OpportunityExecutionPlan $plan): array
    {
        $needsInput = in_array((string) $plan->status, [OpportunityExecutionPlan::STATUS_DRAFT, OpportunityExecutionPlan::STATUS_REVIEWING], true);

        return $this->payload(
            source: $plan,
            workspace: $plan->workspace,
            category: AssistantFeedItem::CATEGORY_EXECUTION,
            state: $needsInput ? AssistantFeedItem::STATE_NEEDS_INPUT : AssistantFeedItem::STATE_PREPARED,
            title: $plan->title,
            summary: $plan->summary ?: $plan->objective,
            baseScore: $plan->expected_impact ?: $plan->priority_score,
            sections: [
                'i_found' => $plan->summary ?: 'An execution path is ready for this opportunity.',
                'i_recommend' => $plan->recommended_format ? 'Execute this as '.$plan->recommended_format.'.' : 'Review the recommended execution path.',
                'i_prepared' => 'I prepared the channel, format, effort estimate, impact estimate, and planned steps.',
                'i_completed' => (string) $plan->status === OpportunityExecutionPlan::STATUS_PLANNED ? 'This recommendation has been marked as planned.' : null,
                'i_need_your_input' => $needsInput ? 'Approve the recommendation or request changes before execution starts.' : null,
            ],
            cta: ['Open recommendation', route('app.opportunities.execution-recommendations.show', $plan)],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function actionRun(AgenticActionRun $run): array
    {
        $status = (string) $run->status;
        $needsInput = in_array($status, [AgenticActionRun::STATUS_PROPOSED, AgenticActionRun::STATUS_APPROVAL_REQUIRED], true);
        $completed = $status === AgenticActionRun::STATUS_COMPLETED;

        return $this->payload(
            source: $run,
            workspace: $run->workspace,
            category: $needsInput ? AssistantFeedItem::CATEGORY_APPROVAL : AssistantFeedItem::CATEGORY_EXECUTION,
            state: $completed ? AssistantFeedItem::STATE_COMPLETED : ($needsInput ? AssistantFeedItem::STATE_NEEDS_INPUT : AssistantFeedItem::STATE_PREPARED),
            title: str_replace('_', ' ', ucfirst((string) $run->action_type)),
            summary: $run->reason,
            baseScore: (float) data_get($run->input_snapshot, 'payload.priority_score', data_get($run->policy_snapshot, 'priority_score', 50)),
            sections: [
                'i_found' => $run->reason ?: 'An Agentic Marketing action is ready in the execution queue.',
                'i_recommend' => $needsInput ? 'Review this action before Argusly runs it.' : 'Let Argusly continue within the configured execution rules.',
                'i_prepared' => 'I prepared the policy check, credit estimate, destination context, and execution state.',
                'i_completed' => $completed ? 'This action completed successfully.' : null,
                'i_need_your_input' => $needsInput ? 'Approve, reject, or request changes for this action.' : null,
            ],
            cta: ['Open approval inbox', route('app.agentic-marketing.approvals.index', ['workspace_id' => $run->workspace_id])],
            context: ['urgent' => $needsInput, 'blocked' => $status === AgenticActionRun::STATUS_BLOCKED],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function signalDetection(SignalDetection $detection): array
    {
        $action = $this->firstText($detection->recommended_actions) ?: 'Review this market change and decide whether Argusly should turn it into an opportunity.';

        return $this->payload(
            source: $detection,
            workspace: $detection->workspace,
            category: AssistantFeedItem::CATEGORY_OPPORTUNITY,
            state: AssistantFeedItem::STATE_FOUND,
            title: $detection->title,
            summary: $detection->summary,
            baseScore: $detection->opportunity_score ?: $detection->impact_score ?: $detection->priority_score,
            sections: [
                'i_found' => $detection->summary ?: $detection->primary_topic ?: 'A market signal may deserve attention.',
                'i_recommend' => $action,
                'i_prepared' => 'I prepared the signal evidence, urgency, confidence, and likely impact.',
                'i_completed' => $detection->resolved_at ? 'This signal has been resolved.' : null,
                'i_need_your_input' => $detection->resolved_at ? null : 'Decide whether Argusly should promote this into an active opportunity.',
            ],
            cta: ['Review signal', route('app.opportunities.candidates.show', $detection)],
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
            category: AssistantFeedItem::CATEGORY_CONTENT_ACTION,
            state: AssistantFeedItem::STATE_RECOMMEND,
            title: (string) data_get($recommendation->payload, 'title', str_replace('_', ' ', (string) $recommendation->type)),
            summary: (string) data_get($recommendation->payload, 'summary', ''),
            baseScore: (float) data_get($recommendation->payload, 'priority_score', 55),
            sections: [
                'i_found' => (string) data_get($recommendation->payload, 'summary', 'A content improvement is available.'),
                'i_recommend' => (string) data_get($recommendation->payload, 'recommended_action', 'Review and apply this content improvement.'),
                'i_prepared' => 'I prepared the content context and suggested action.',
                'i_completed' => null,
                'i_need_your_input' => 'Open the content item and decide whether to apply the recommendation.',
            ],
            cta: $content ? ['Open content', route('app.content.show', $content)] : null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function draftRecommendation(DraftRecommendation $recommendation): array
    {
        $draft = $recommendation->draft;
        $workspace = $draft?->clientSite?->workspace ?? $draft?->brief?->clientSite?->workspace;

        return $this->payload(
            source: $recommendation,
            workspace: $workspace,
            category: AssistantFeedItem::CATEGORY_CONTENT_ACTION,
            state: AssistantFeedItem::STATE_RECOMMEND,
            title: $recommendation->title,
            summary: $recommendation->summary,
            baseScore: $recommendation->priority_score,
            sections: [
                'i_found' => $recommendation->why_it_matters ?: $recommendation->summary,
                'i_recommend' => $recommendation->suggested_action ?: 'Review this draft improvement.',
                'i_prepared' => 'I prepared the draft context, impact level, effort level, and confidence.',
                'i_completed' => null,
                'i_need_your_input' => 'Review the draft and decide whether to apply the recommendation.',
            ],
            cta: $draft ? ['Open draft', route('app.drafts.show', $draft)] : null,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function content(Content $content): array
    {
        $status = (string) ($content->status?->value ?? $content->status);
        $published = $status === 'published';

        return $this->payload(
            source: $content,
            workspace: $content->workspace,
            category: AssistantFeedItem::CATEGORY_CONTENT_ACTION,
            state: $published ? AssistantFeedItem::STATE_COMPLETED : AssistantFeedItem::STATE_PREPARED,
            title: $content->title,
            summary: $content->primary_keyword,
            baseScore: 50,
            sections: [
                'i_found' => 'A content item is in the pipeline.',
                'i_recommend' => $published ? 'Review performance and look for the next growth opportunity.' : 'Move this content item to the next stage.',
                'i_prepared' => 'I prepared its current status, site context, and next workflow step.',
                'i_completed' => $published ? 'This content has been published.' : null,
                'i_need_your_input' => $published ? null : 'Open the content item and continue production.',
            ],
            cta: ['Open content', route('app.content.show', $content)],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function generic(Model $source): array
    {
        return $this->payload(
            source: $source,
            workspace: method_exists($source, 'workspace') ? $source->workspace : null,
            category: AssistantFeedItem::CATEGORY_RECOMMENDATION,
            state: AssistantFeedItem::STATE_FOUND,
            title: class_basename($source),
            summary: null,
            baseScore: 50,
            sections: [
                'i_found' => 'I found an item Argusly can surface in the assistant feed.',
                'i_recommend' => 'Review this item in its source workspace.',
                'i_prepared' => 'I prepared a normalized assistant card for this source.',
                'i_completed' => null,
                'i_need_your_input' => null,
            ],
        );
    }

    /**
     * @param array<string,mixed> $sections
     * @param array{0:string,1:string}|null $cta
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function payload(
        Model $source,
        ?Workspace $workspace,
        string $category,
        string $state,
        string $title,
        ?string $summary,
        float|int|null $baseScore,
        array $sections,
        ?array $cta = null,
        array $context = [],
    ): array {
        $priority = $this->prioritySystem->score($baseScore, $state, $category, $context);

        return array_merge(array_filter([
            'workspace_id' => $workspace?->id,
            'organization_id' => $workspace?->organization_id,
            'source_type' => $source::class,
            'source_id' => (string) $source->getKey(),
            'source_signature' => $this->signature($source, $workspace, $category),
            'category' => $category,
            'assistant_state' => $state,
            'title' => trim($title) !== '' ? $title : class_basename($source),
            'summary' => $summary,
            'priority_score' => $priority,
            'priority_label' => $this->prioritySystem->label($priority),
            'status' => $state === AssistantFeedItem::STATE_COMPLETED ? AssistantFeedItem::STATUS_COMPLETED : AssistantFeedItem::STATUS_ACTIVE,
            'visible_at' => now(),
            'metadata' => array_filter([
                'source_class' => $source::class,
                'source_key' => (string) $source->getKey(),
                'base_score' => $baseScore,
                'context' => $context,
            ]),
            'primary_cta_label' => $cta[0] ?? null,
            'primary_cta_url' => $cta[1] ?? null,
        ], fn ($value): bool => $value !== null),
            $this->normalizeSections($sections)
        );
    }

    private function signature(Model $source, ?Workspace $workspace, string $category): string
    {
        return sha1(implode('|', [
            $workspace?->id ?? 'global',
            $category,
            $source::class,
            (string) $source->getKey(),
        ]));
    }

    /**
     * @param array<string,mixed> $sections
     * @return array<string,string|null>
     */
    private function normalizeSections(array $sections): array
    {
        return [
            'i_found' => $this->nullableText($sections['i_found'] ?? null),
            'i_recommend' => $this->nullableText($sections['i_recommend'] ?? null),
            'i_prepared' => $this->nullableText($sections['i_prepared'] ?? null),
            'i_completed' => $this->nullableText($sections['i_completed'] ?? null),
            'i_need_your_input' => $this->nullableText($sections['i_need_your_input'] ?? null),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
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
}
