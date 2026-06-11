<?php

namespace App\Services\Journey;

use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FirstValueExperienceService
{
    public function __construct(private readonly WorkspaceJourneyService $journey)
    {
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function celebrations(Workspace $workspace): Collection
    {
        return collect([
            $this->celebration(
                LlmTrackingQuery::query()->where('workspace_id', $workspace->id)->count() === 1,
                $this->runtime('First Query Created'),
                $this->runtime('Your first AI Visibility question is ready to run.')
            ),
            $this->celebration(
                LlmTrackingQueryRun::query()->whereHas('trackingQuery', fn ($query) => $query->where('workspace_id', $workspace->id))->count() === 1,
                $this->runtime('First Run Completed'),
                $this->runtime('Argusly now has AI answer evidence to analyze.')
            ),
            $this->celebration(
                SignalEvent::query()->where('workspace_id', $workspace->id)->count() === 1,
                $this->runtime('First Signal Found'),
                $this->runtime('The first piece of intelligence evidence has been captured.')
            ),
            $this->celebration(
                SignalDetection::query()->where('workspace_id', $workspace->id)->count() === 1,
                $this->runtime('First Detection Created'),
                $this->runtime('Related evidence has been grouped into something reviewable.')
            ),
            $this->celebration(
                Opportunity::query()->where('workspace_id', $workspace->id)->count() === 1,
                $this->runtime('First Opportunity Created'),
                $this->runtime('A signal has become an actionable opportunity.')
            ),
            $this->celebration(
                OpportunityExecutionPlan::query()->where('workspace_id', $workspace->id)->active()->count() === 1,
                $this->runtime('First Execution Plan Created'),
                $this->runtime('The opportunity now has a concrete plan.')
            ),
        ])->filter()->values();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function firstSignalCard(Workspace $workspace): ?array
    {
        if (SignalEvent::query()->where('workspace_id', $workspace->id)->count() !== 1) {
            return null;
        }

        $event = SignalEvent::query()
            ->where('workspace_id', $workspace->id)
            ->with(['signalSource', 'clientSite'])
            ->oldest('created_at')
            ->first();

        if (! $event) {
            return null;
        }

        return [
            'type' => $this->runtime('Signals'),
            'title' => $this->runtime('Your first signal has been detected'),
            'what_happened' => $this->runtime('A signal is a single piece of market, AI visibility, competitor, or brand evidence that may be useful later.'),
            'why_detected' => $this->runtime('We found evidence about :topic connected to :entity.', [
                'topic' => $this->fallback($event->topic, $this->runtime('a tracked topic')),
                'entity' => $this->fallback($event->entity_name, $this->runtime('your market')),
            ]),
            'next_step' => $this->runtime('Run or review Signal Intelligence so related signals can be grouped into detections.'),
            'expected_value' => $this->runtime('Signals help explain what changed before you decide whether it deserves action.'),
            'action' => $this->journey->getRecommendedAction($workspace),
            'facts' => [
                $this->runtime('Source') => $event->signalSource?->name ?? $this->runtime('AI Visibility or configured source'),
                $this->runtime('Topic') => $this->fallback($event->topic),
                $this->runtime('Entity') => $this->fallback($event->entity_name),
                $this->runtime('Confidence') => number_format((float) $event->confidence_score, 0),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function detectionCard(SignalDetection $detection): ?array
    {
        if (SignalDetection::query()->where('workspace_id', $detection->workspace_id)->count() !== 1) {
            return null;
        }

        return [
            'type' => $this->runtime('Detections'),
            'title' => $this->runtime('Your first opportunity signal is ready for review'),
            'what_happened' => $this->runtime('A detection groups related signals into one reviewable insight.'),
            'why_detected' => $this->runtime('We found a recurring topic that may represent a growth opportunity.'),
            'next_step' => $this->runtime('Review the evidence, then promote it when the signal is worth turning into an opportunity.'),
            'expected_value' => $this->runtime('Review keeps the workflow focused on useful opportunities instead of every raw signal.'),
            'action' => $this->journey->getRecommendedAction($detection->workspace),
            'facts' => [
                $this->runtime('Topic') => $this->fallback($detection->primary_topic),
                $this->runtime('Entity') => $this->fallback($detection->primary_entity),
                $this->runtime('Confidence') => number_format((float) $detection->confidence_score, 0),
                $this->runtime('Opportunity score') => number_format((float) $detection->opportunity_score, 0),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function firstDetectionCard(Workspace $workspace): ?array
    {
        $detection = SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->with('workspace')
            ->oldest('created_at')
            ->first();

        return $detection ? $this->detectionCard($detection) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function opportunityCard(Opportunity $opportunity): ?array
    {
        if (Opportunity::query()->where('workspace_id', $opportunity->workspace_id)->count() !== 1) {
            return null;
        }

        return [
            'type' => $this->runtime('Opportunities'),
            'title' => $this->runtime('Your first opportunity is ready'),
            'what_happened' => $this->runtime('An opportunity is a reviewed market signal that can become a plan, brief, draft, and governance decision.'),
            'why_detected' => $this->runtime('This opportunity was created from related signals that point to a topic worth acting on.'),
            'next_step' => $this->runtime('Approve the opportunity, then create an execution plan to decide what content or action should happen next.'),
            'expected_value' => $this->runtime('Opportunities turn intelligence into a practical content or execution decision.'),
            'action' => $this->journey->getRecommendedAction($opportunity->workspace),
            'facts' => [
                $this->runtime('Topic') => $this->fallback($opportunity->topic),
                $this->runtime('Priority') => number_format((float) $opportunity->priority_score, 0),
                $this->runtime('Confidence') => number_format((float) $opportunity->confidence_score, 0),
                $this->runtime('Status') => Str::of((string) ($opportunity->status?->value ?? $opportunity->status))->replace('_', ' ')->headline()->toString(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function firstOpportunityCard(Workspace $workspace): ?array
    {
        $opportunity = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->with('workspace')
            ->oldest('created_at')
            ->first();

        return $opportunity ? $this->opportunityCard($opportunity) : null;
    }

    /**
     * @return array<string,string>|null
     */
    private function celebration(bool $condition, string $title, string $description): ?array
    {
        return $condition ? compact('title', 'description') : null;
    }

    private function fallback(?string $value, ?string $fallback = null): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : ($fallback ?? $this->runtime('Not specified'));
    }

    /**
     * @param array<string,mixed> $replace
     */
    private function runtime(string $key, array $replace = []): string
    {
        $lines = trans('app.runtime');
        $translation = is_array($lines) ? (string) ($lines[$key] ?? $key) : $key;

        foreach ($replace as $placeholder => $value) {
            $translation = str_replace(':'.$placeholder, (string) $value, $translation);
        }

        return $translation;
    }
}
