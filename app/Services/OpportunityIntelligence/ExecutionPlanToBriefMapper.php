<?php

namespace App\Services\OpportunityIntelligence;

use App\Models\OpportunityExecutionPlan;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ExecutionPlanToBriefMapper
{
    /**
     * @return array<string,mixed>
     */
    public function map(OpportunityExecutionPlan $plan, User $user, string $clientSiteId): array
    {
        $plan->loadMissing(['opportunity.signals']);

        $sourceContext = $this->sourceContext($plan);
        $contentType = $this->contentTypeFor((string) $plan->recommended_format);

        return [
            'client_site_id' => $clientSiteId,
            'created_by_user_id' => (int) $user->id,
            'status' => 'draft',
            'source' => 'opportunity_execution_plan',
            'title' => Str::limit((string) ($plan->title ?: $plan->opportunity?->title ?: 'Opportunity execution brief'), 180, ''),
            'language' => 'nl',
            'content_type' => $contentType,
            'output_type' => $contentType,
            'intent' => Str::limit((string) ($plan->objective ?: $plan->summary), 255, ''),
            'audience' => $this->audienceFor($plan),
            'target_audience' => $this->audienceFor($plan),
            'tone_of_voice' => 'clear, practical and evidence-led',
            'unique_angle' => Str::limit((string) ($plan->opportunity?->summary ?: $plan->summary), 500, ''),
            'key_points' => $this->keyPoints($plan),
            'call_to_action' => 'Turn this opportunity into a concrete content asset.',
            'notes' => $this->notes($plan, $sourceContext),
            'progress' => 0,
            'client_refs' => $sourceContext,
            'wp_site_id' => $clientSiteId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function sourceContext(OpportunityExecutionPlan $plan): array
    {
        $signalDetectionIds = collect(data_get($plan->source_evidence, 'signals', []))
            ->pluck('signal_detection_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $signalEventIds = collect($plan->opportunity?->signals ?? [])
            ->flatMap(fn ($signal): array => (array) data_get($signal, 'metadata.linked_signal_event_ids', []))
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'client_type' => 'opportunity_execution_plan',
            'source' => 'opportunity_intelligence',
            'execution_plan_id' => (string) $plan->id,
            'opportunity_execution_plan_id' => (string) $plan->id,
            'opportunity_id' => (string) $plan->opportunity_id,
            'workspace_id' => (string) $plan->workspace_id,
            'signal_detection_ids' => $signalDetectionIds,
            'signal_event_ids' => $signalEventIds,
            'evidence_summary' => data_get($plan->source_evidence, 'summary', []),
            'planned_steps' => array_values((array) $plan->planned_steps),
            'recommended_actions' => array_values((array) ($plan->opportunity?->recommended_actions ?? [])),
        ];
    }

    private function contentTypeFor(string $format): string
    {
        return match ($format) {
            'comparison_content_and_social_draft' => 'comparison',
            'short_insight_and_blog_brief', 'content_refresh_with_supporting_post' => 'blog',
            default => 'article',
        };
    }

    private function audienceFor(OpportunityExecutionPlan $plan): string
    {
        $category = (string) ($plan->opportunity?->category?->value ?? $plan->opportunity?->category ?? '');

        return str_contains($category, 'competitor')
            ? 'B2B buyers comparing alternatives'
            : 'B2B decision makers researching the topic';
    }

    /**
     * @return array<int,string>
     */
    private function keyPoints(OpportunityExecutionPlan $plan): array
    {
        return collect((array) $plan->planned_steps)
            ->map(fn ($step): string => trim((string) (($step['title'] ?? 'Step').' - '.($step['description'] ?? ''))))
            ->filter()
            ->take(7)
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $sourceContext
     */
    private function notes(OpportunityExecutionPlan $plan, array $sourceContext): string
    {
        $lines = [
            'Created from Opportunity Execution Plan.',
            '',
            'Objective: '.(string) $plan->objective,
            'Recommended channel: '.(string) $plan->recommended_channel,
            'Recommended format: '.(string) $plan->recommended_format,
            'Opportunity ID: '.(string) $plan->opportunity_id,
            'Execution plan ID: '.(string) $plan->id,
        ];

        $detections = Arr::wrap($sourceContext['signal_detection_ids'] ?? []);
        if ($detections !== []) {
            $lines[] = 'Signal detections: '.implode(', ', $detections);
        }

        $events = Arr::wrap($sourceContext['signal_event_ids'] ?? []);
        if ($events !== []) {
            $lines[] = 'Signal events: '.implode(', ', $events);
        }

        $steps = $this->keyPoints($plan);
        if ($steps !== []) {
            $lines[] = '';
            $lines[] = 'Planned steps:';
            foreach ($steps as $index => $step) {
                $lines[] = ($index + 1).'. '.$step;
            }
        }

        return implode("\n", $lines);
    }
}
