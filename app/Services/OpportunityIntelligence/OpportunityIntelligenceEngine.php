<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OpportunityIntelligenceEngine
{
    public function __construct(
        private readonly OpportunityScoringEngine $scoring,
        private readonly RecommendedActionBuilder $actions,
    ) {}

    /**
     * @return array<string,int>
     */
    public function run(Workspace $workspace): array
    {
        $created = 0;
        $updated = 0;

        OpportunitySignal::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->get()
            ->groupBy(fn (OpportunitySignal $signal): string => $this->clusterKey($workspace, $signal))
            ->each(function (Collection $signals) use ($workspace, &$created, &$updated): void {
                $opportunity = $this->persistGroup($workspace, $signals);
                $opportunity->wasRecentlyCreated ? $created++ : $updated++;
            });

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @param Collection<int, OpportunitySignal> $signals
     */
    private function persistGroup(Workspace $workspace, Collection $signals): Opportunity
    {
        /** @var OpportunitySignal $first */
        $first = $signals->first();
        $category = $first->category ?: $this->categoryForSource((string) ($first->source?->value ?? $first->source));
        $topic = $first->topic ?: $first->entity ?: 'Untitled opportunity';
        $score = $this->scoring->score($category, $signals);
        $actions = $this->actions->build($category, $signals);
        $hash = hash('sha256', implode('|', [
            'opportunity_cluster',
            $this->clusterKey($workspace, $first),
        ]));
        $promotedSignals = $signals->filter(fn (OpportunitySignal $signal): bool => $this->isPromotedSignalIntelligenceSignal($signal));
        $existingStatus = Opportunity::query()
            ->where('workspace_id', (string) $workspace->id)
            ->where('dedupe_hash', $hash)
            ->value('status');

        return DB::transaction(function () use ($workspace, $signals, $first, $category, $topic, $score, $actions, $hash, $promotedSignals, $existingStatus): Opportunity {
            $opportunity = Opportunity::query()->updateOrCreate(
                [
                    'workspace_id' => (string) $workspace->id,
                    'dedupe_hash' => $hash,
                ],
                [
                    'organization_id' => $workspace->organization_id,
                    'client_site_id' => $first->client_site_id,
                    'content_id' => $first->content_id,
                    'content_cluster_id' => $first->content_cluster_id,
                    'campaign_id' => $first->campaign_id,
                    'category' => $category->value,
                    'status' => $existingStatus ?: OpportunityStatus::OPEN->value,
                    'title' => $this->title($category, (string) $topic),
                    'topic' => $topic,
                    'summary' => $this->summary($category, $signals),
                    'priority_score' => $score['priority_score'],
                    'confidence_score' => $score['confidence_score'],
                    'impact_score' => $score['impact_score'],
                    'urgency_score' => $score['urgency_score'],
                    'effort_score' => $score['effort_score'],
                    'score_breakdown' => $score['score_breakdown'],
                    'recommended_actions' => $actions,
                    'evidence' => $signals->pluck('evidence')->filter()->values()->all(),
                    'source_signal_summary' => [
                        'count' => $signals->count(),
                        'sources' => $signals->pluck('source')->map(fn ($source) => $source?->value ?? $source)->unique()->values()->all(),
                        'average_strength' => round((float) $signals->avg('signal_strength'), 2),
                        'promoted_signal_intelligence_count' => $promotedSignals->count(),
                        'signal_detection_ids' => $promotedSignals
                            ->pluck('metadata.signal_detection_id')
                            ->filter()
                            ->map(fn ($id): string => (string) $id)
                            ->unique()
                            ->values()
                            ->all(),
                    ],
                    'metadata' => [
                        'has_signal_intelligence_input' => $promotedSignals->isNotEmpty(),
                        'signal_detection_ids' => $promotedSignals
                            ->pluck('metadata.signal_detection_id')
                            ->filter()
                            ->map(fn ($id): string => (string) $id)
                            ->unique()
                            ->values()
                            ->all(),
                        'linked_signal_event_ids' => $promotedSignals
                            ->flatMap(fn (OpportunitySignal $signal): array => (array) data_get($signal->metadata, 'linked_signal_event_ids', []))
                            ->filter()
                            ->map(fn ($id): string => (string) $id)
                            ->unique()
                            ->values()
                            ->all(),
                    ],
                    'first_seen_at' => $signals->min('observed_at') ?: now(),
                    'last_seen_at' => $signals->max('observed_at') ?: now(),
                ]
            );

            foreach ($signals as $signal) {
                DB::table('opportunity_signal_links')->updateOrInsert(
                    [
                        'opportunity_id' => (string) $opportunity->id,
                        'opportunity_signal_id' => (string) $signal->id,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'weight' => round(((float) $signal->signal_strength / 100), 2),
                        'contribution' => json_encode([
                            'source' => $signal->source?->value ?? $signal->source,
                            'strength' => $signal->signal_strength,
                            'confidence' => $signal->confidence,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            return $opportunity;
        });
    }

    private function categoryForSource(string $source): OpportunityCategory
    {
        return match ($source) {
            'search_trends' => OpportunityCategory::TREND_OPPORTUNITY,
            'ai_citation_tracking' => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY,
            'content_decay' => OpportunityCategory::REFRESH_OPPORTUNITY,
            'engagement_analytics' => OpportunityCategory::ENGAGEMENT_OPPORTUNITY,
            'competitor_intelligence' => OpportunityCategory::COMPETITOR_MOVEMENT,
            'signal_intelligence' => OpportunityCategory::CONTENT_GAP,
            default => OpportunityCategory::CONTENT_GAP,
        };
    }

    private function clusterKey(Workspace $workspace, OpportunitySignal $signal): string
    {
        $category = $signal->category?->value ?? $this->categoryForSource((string) ($signal->source?->value ?? $signal->source))->value;
        $topic = strtolower(trim((string) ($signal->topic ?: $signal->entity ?: $signal->content_id ?: 'general')));

        if (! $this->isPromotedSignalIntelligenceSignal($signal)) {
            return implode('|', [
                (string) $workspace->id,
                $category,
                $topic,
                (string) $signal->content_id,
                (string) $signal->content_cluster_id,
            ]);
        }

        return implode('|', [
            (string) $workspace->id,
            (string) $signal->client_site_id,
            $category,
            $topic,
            $this->periodKey($signal),
            $this->scoreBand((float) data_get($signal->metrics, 'urgency_score', 0)),
            $this->scoreBand((float) data_get($signal->metrics, 'impact_score', 0)),
        ]);
    }

    private function isPromotedSignalIntelligenceSignal(OpportunitySignal $signal): bool
    {
        $source = $signal->source?->value ?? $signal->source;

        return $source === OpportunitySignalSource::SIGNAL_INTELLIGENCE->value
            && filled(data_get($signal->metadata, 'signal_detection_id'));
    }

    private function periodKey(OpportunitySignal $signal): string
    {
        return ($signal->observed_at ?? now())->copy()->startOfWeek()->toDateString();
    }

    private function scoreBand(float $score): string
    {
        return match (true) {
            $score >= 80 => 'very_high',
            $score >= 60 => 'high',
            $score >= 40 => 'medium',
            $score > 0 => 'low',
            default => 'unknown',
        };
    }

    private function title(OpportunityCategory $category, string $topic): string
    {
        return sprintf('%s: %s', str_replace('_', ' ', ucfirst($category->value)), $topic);
    }

    /**
     * @param Collection<int, OpportunitySignal> $signals
     */
    private function summary(OpportunityCategory $category, Collection $signals): string
    {
        $sources = $signals->pluck('source')->map(fn ($source) => $source?->value ?? $source)->unique()->implode(', ');

        return sprintf(
            'Detected from %d stored signal(s): %s. Scores are deterministic and explainable; no generative AI was used.',
            $signals->count(),
            $sources ?: str_replace('_', ' ', $category->value)
        );
    }
}
