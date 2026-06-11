<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\OpportunityCategory;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class OpportunityExecutionPlanBuilder
{
    public function build(Opportunity $opportunity, User $user): OpportunityExecutionPlan
    {
        $opportunity->loadMissing(['workspace', 'signals']);

        if (! in_array((string) ($opportunity->status?->value ?? $opportunity->status), ['approved', 'reviewing'], true)) {
            throw new AuthorizationException('Only reviewing or approved opportunities can be planned.');
        }

        $existing = $opportunity->activeExecutionPlans()->first();
        if ($existing) {
            throw new AuthorizationException('This opportunity already has an active execution plan.');
        }

        return DB::transaction(function () use ($opportunity, $user): OpportunityExecutionPlan {
            $rules = $this->rules($opportunity);

            return OpportunityExecutionPlan::query()->create([
                'organization_id' => $opportunity->organization_id,
                'workspace_id' => $opportunity->workspace_id,
                'client_site_id' => $opportunity->client_site_id,
                'opportunity_id' => $opportunity->id,
                'status' => OpportunityExecutionPlan::STATUS_DRAFT,
                'title' => 'Execution plan: '.$opportunity->title,
                'summary' => $opportunity->summary,
                'objective' => $rules['objective'],
                'recommended_channel' => $rules['channel'],
                'recommended_format' => $rules['format'],
                'priority_score' => $opportunity->priority_score,
                'estimated_effort' => $rules['effort'],
                'expected_impact' => $rules['impact'],
                'planned_steps' => $rules['steps'],
                'source_evidence' => $this->sourceEvidence($opportunity),
                'metadata' => [
                    'opportunity_category' => $opportunity->category?->value ?? $opportunity->category,
                    'opportunity_status_at_creation' => $opportunity->status?->value ?? $opportunity->status,
                    'source_signal_count' => $opportunity->signals->count(),
                    'signal_detection_ids' => $opportunity->metadata['signal_detection_ids'] ?? [],
                ],
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * @return array{objective:string,channel:string,format:string,effort:float,impact:float,steps:array<int,array<string,mixed>>}
     */
    private function rules(Opportunity $opportunity): array
    {
        $category = $opportunity->category instanceof OpportunityCategory
            ? $opportunity->category
            : OpportunityCategory::from((string) $opportunity->category);
        $topic = (string) ($opportunity->topic ?: 'this opportunity');

        return match ($category) {
            OpportunityCategory::AI_VISIBILITY_OPPORTUNITY => [
                'objective' => "Improve AI visibility and citation coverage for {$topic}.",
                'channel' => 'owned_content',
                'format' => 'content_refresh_with_supporting_post',
                'effort' => 58.0,
                'impact' => max(70.0, (float) $opportunity->impact_score),
                'steps' => $this->steps([
                    ['Audit current entity and citation coverage', 'Review the linked Signal Intelligence evidence and identify citation gaps.'],
                    ['Update existing content', 'Add clear answers, entity references, and supporting source context.'],
                    ['Add answer block', 'Create a concise answer section for extractability.'],
                    ['Improve citations', 'Strengthen references, schema, and internal links around the topic.'],
                    ['Publish supporting post', 'Create a short supporting article or update note that reinforces the topic.'],
                    ['Monitor impact', 'Track related AI visibility and signal changes after publication.'],
                ]),
            ],
            OpportunityCategory::COMPETITOR_MOVEMENT => [
                'objective' => "Respond to competitor movement around {$topic}.",
                'channel' => 'content_and_social',
                'format' => 'comparison_content_and_social_draft',
                'effort' => 64.0,
                'impact' => max(68.0, (float) $opportunity->impact_score),
                'steps' => $this->steps([
                    ['Analyze competitor topic', 'Review the competitor movement evidence and identify the strongest angle.'],
                    ['Draft comparison content', 'Prepare a comparison or response page that clarifies positioning.'],
                    ['Map campaign angle', 'Connect the response to an existing or planned campaign theme.'],
                    ['Plan social draft', 'Prepare a governed social post for distribution review.'],
                    ['Monitor impact', 'Track competitor pressure and owned visibility after publishing.'],
                ]),
            ],
            OpportunityCategory::TREND_OPPORTUNITY => [
                'objective' => "Capture rising demand for {$topic}.",
                'channel' => 'campaign_content',
                'format' => 'short_insight_and_blog_brief',
                'effort' => 52.0,
                'impact' => max(64.0, (float) $opportunity->impact_score),
                'steps' => $this->steps([
                    ['Create short insight post', 'Summarize the trend and why it matters now.'],
                    ['Prepare blog briefing', 'Turn the trend into a structured content brief.'],
                    ['Link to campaign', 'Attach the topic to a relevant campaign idea or cluster.'],
                    ['Define measurement', 'Track signal strength, visibility, and engagement after launch.'],
                ]),
            ],
            default => [
                'objective' => "Turn {$topic} into a governed content action.",
                'channel' => 'owned_content',
                'format' => 'content_brief',
                'effort' => 50.0,
                'impact' => max(60.0, (float) $opportunity->impact_score),
                'steps' => $this->steps([
                    ['Review source evidence', 'Validate the signal lineage and opportunity rationale.'],
                    ['Create content brief', 'Prepare a structured brief with audience, angle, and evidence.'],
                    ['Plan campaign idea', 'Connect the action to a campaign or content cluster.'],
                    ['Prepare distribution draft', 'Draft a social or newsletter angle for later approval.'],
                    ['Monitor outcome', 'Review signals after the planned content ships.'],
                ]),
            ],
        };
    }

    /**
     * @param array<int,array{0:string,1:string}> $steps
     * @return array<int,array<string,mixed>>
     */
    private function steps(array $steps): array
    {
        return collect($steps)
            ->values()
            ->map(fn (array $step, int $index): array => [
                'position' => $index + 1,
                'title' => $step[0],
                'description' => $step[1],
                'status' => 'pending',
                'automatic_execution' => false,
            ])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceEvidence(Opportunity $opportunity): array
    {
        return [
            'opportunity_id' => (string) $opportunity->id,
            'summary' => $opportunity->summary,
            'score_breakdown' => $opportunity->score_breakdown ?? [],
            'source_signal_summary' => $opportunity->source_signal_summary ?? [],
            'signals' => $opportunity->signals->map(fn ($signal): array => [
                'id' => (string) $signal->id,
                'source' => $signal->source?->value ?? $signal->source,
                'category' => $signal->category?->value ?? $signal->category,
                'topic' => $signal->topic,
                'entity' => $signal->entity,
                'signal_strength' => (float) $signal->signal_strength,
                'confidence' => (float) $signal->confidence,
                'signal_detection_id' => data_get($signal->metadata, 'signal_detection_id'),
                'evidence_summary' => data_get($signal->evidence, 'evidence_summary', []),
            ])->values()->all(),
        ];
    }
}
