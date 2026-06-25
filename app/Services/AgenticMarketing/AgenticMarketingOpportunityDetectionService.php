<?php

namespace App\Services\AgenticMarketing;

use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\AgenticMarketingRun;
use App\Models\AgenticMarketingRunItem;
use App\Services\AgenticMarketing\OpportunityDetection\AgenticMarketingOpportunityDetector;
use App\Services\AgenticMarketing\OpportunityDetection\AiVisibilityGapOpportunityDetector;
use App\Services\AgenticMarketing\OpportunityDetection\ContentNetworkGapOpportunityDetector;
use App\Services\AgenticMarketing\OpportunityDetection\DetectedOpportunity;
use App\Services\AgenticMarketing\OpportunityDetection\InternalLinkOpportunityDetector;
use App\Services\AgenticMarketing\OpportunityDetection\LocalizationGapOpportunityDetector;
use App\Services\AgenticMarketing\OpportunityDetection\LlmTrackingAiVisibilityOpportunityDetector;
use App\Services\AgenticMarketing\OpportunityDetection\RefreshLifecycleOpportunityDetector;
use App\Services\AgenticMarketing\OpportunityDetection\SeoIndexabilityOpportunityDetector;
use App\Services\AgenticMarketing\OpportunityDetection\StructuredAnswerGapOpportunityDetector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgenticMarketingOpportunityDetectionService
{
    /**
     * @param array<int,AgenticMarketingOpportunityDetector>|null $detectors
     */
    public function __construct(
        private readonly ?array $detectors = null,
        private readonly ?AgenticMarketingDecisionEngine $decisionEngine = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function detect(?string $objectiveId = null): array
    {
        $objectives = $this->objectives($objectiveId);
        $summary = [
            'objectives' => $objectives->count(),
            'created' => 0,
            'reused' => 0,
            'failed' => 0,
            'runs' => [],
        ];

        foreach ($objectives as $objective) {
            $result = $this->detectForObjective($objective);
            $summary['created'] += (int) $result['created'];
            $summary['reused'] += (int) $result['reused'];
            $summary['failed'] += $result['status'] === 'failed' ? 1 : 0;
            $summary['runs'][] = $result;
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function detectForObjective(AgenticMarketingObjective $objective): array
    {
        $run = AgenticMarketingRun::query()->create([
            'objective_id' => $objective->id,
            'status' => AgenticMarketingRun::STATUS_QUEUED,
            'payload' => [
                'type' => 'opportunity_detection',
                'detectors' => collect($this->detectors())->map(fn ($detector): string => $detector::class)->values()->all(),
            ],
        ]);

        try {
            $run->markRunning();
            app(AgenticMarketingAuditLogger::class)->record($run->loadMissing('objective'), 'run.started', null, $run->attributesToArray());

            $detectorFailures = [];
            $candidates = $this->rankedCandidates($objective, $run, $detectorFailures);
            $created = 0;
            $reused = 0;
            $opportunityIds = [];

            foreach ($candidates as $candidate) {
                $opportunity = AgenticMarketingOpportunity::createOrReuseOpen(
                    $candidate->attributes((string) $objective->id)
                );

                if ($opportunity->wasRecentlyCreated) {
                    $created++;
                } else {
                    $reused++;
                    $this->refreshReusableOpportunity($opportunity, $candidate);
                }

                $opportunityIds[] = (string) $opportunity->id;
            }

            $result = [
                'status' => $detectorFailures === [] ? 'completed' : 'completed_with_warnings',
                'objective_id' => (string) $objective->id,
                'run_id' => (string) $run->id,
                'detected' => $candidates->count(),
                'created' => $created,
                'reused' => $reused,
                'failed_detectors' => count($detectorFailures),
                'detector_failures' => $detectorFailures,
                'opportunity_ids' => array_values(array_unique($opportunityIds)),
            ];

            $run->markCompleted($result);
            app(AgenticMarketingAuditLogger::class)->record($run->loadMissing('objective'), 'run.completed', null, $result);

            return $result;
        } catch (Throwable $exception) {
            Log::error('Agentic Marketing opportunity detection failed', [
                'objective_id' => (string) $objective->id,
                'run_id' => (string) $run->id,
                'error' => $exception->getMessage(),
            ]);

            $run->markFailed($exception->getMessage());
            app(AgenticMarketingAuditLogger::class)->record($run->loadMissing('objective'), 'run.failed', null, [
                'error_message' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'objective_id' => (string) $objective->id,
                'run_id' => (string) $run->id,
                'created' => 0,
                'reused' => 0,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return Collection<int,DetectedOpportunity>
     */
    private function rankedCandidates(AgenticMarketingObjective $objective, AgenticMarketingRun $run, array &$detectorFailures = []): Collection
    {
        return collect($this->detectors())
            ->flatMap(function (AgenticMarketingOpportunityDetector $detector) use ($objective, $run, &$detectorFailures): array {
                $item = AgenticMarketingRunItem::query()->create([
                    'run_id' => $run->id,
                    'objective_id' => $objective->id,
                    'type' => AgenticMarketingRunItem::TYPE_DETECTION,
                    'name' => class_basename($detector),
                    'status' => AgenticMarketingRunItem::STATUS_QUEUED,
                    'payload' => ['detector' => $detector::class],
                ]);

                try {
                    $item->markRunning();
                    $candidates = $detector->detect($objective);
                    $item->markCompleted(['detected' => count($candidates)]);

                    return $candidates;
                } catch (Throwable $exception) {
                    $item->markFailed($exception->getMessage());
                    $detectorFailures[] = [
                        'detector' => $detector::class,
                        'message' => $exception->getMessage(),
                    ];
                    Log::warning('Agentic Marketing opportunity detector failed', [
                        'objective_id' => (string) $objective->id,
                        'run_id' => (string) $run->id,
                        'detector' => $detector::class,
                        'error' => $exception->getMessage(),
                    ]);

                    return [];
                }
            })
            ->map(fn (DetectedOpportunity $candidate): DetectedOpportunity => $this->decisionEngine()->score($candidate))
            ->sortByDesc(fn (DetectedOpportunity $candidate): int => $candidate->priorityScore)
            ->values();
    }

    /**
     * @return Collection<int,AgenticMarketingObjective>
     */
    private function objectives(?string $objectiveId): Collection
    {
        return AgenticMarketingObjective::query()
            ->when($objectiveId, fn ($query): mixed => $query->whereKey($objectiveId))
            ->where('status', 'active')
            ->whereNotNull('workspace_id')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return array<int,AgenticMarketingOpportunityDetector>
     */
    private function detectors(): array
    {
        return $this->detectors ?? [
            app(RefreshLifecycleOpportunityDetector::class),
            app(InternalLinkOpportunityDetector::class),
            app(LocalizationGapOpportunityDetector::class),
            app(StructuredAnswerGapOpportunityDetector::class),
            app(SeoIndexabilityOpportunityDetector::class),
            app(ContentNetworkGapOpportunityDetector::class),
            app(AiVisibilityGapOpportunityDetector::class),
            app(LlmTrackingAiVisibilityOpportunityDetector::class),
        ];
    }

    private function decisionEngine(): AgenticMarketingDecisionEngine
    {
        return $this->decisionEngine ?? app(AgenticMarketingDecisionEngine::class);
    }

    private function refreshReusableOpportunity(AgenticMarketingOpportunity $opportunity, DetectedOpportunity $candidate): void
    {
        $updates = [];

        if ((int) $opportunity->priority_score !== $candidate->priorityScore) {
            $updates['priority_score'] = $candidate->priorityScore;
        }

        if ((string) $opportunity->title !== $candidate->title) {
            $updates['title'] = $candidate->title;
        }

        if (($opportunity->payload ?? []) !== $candidate->payload) {
            $updates['payload'] = $candidate->payload;
        }

        if ($updates !== []) {
            $opportunity->forceFill($updates)->save();
        }
    }
}
