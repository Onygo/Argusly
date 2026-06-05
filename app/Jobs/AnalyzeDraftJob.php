<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Draft;
use App\Models\DraftAnalysis;
use App\Services\Drafts\DraftIntelligenceBillingService;
use App\Services\Drafts\DraftIntelligenceService;
use App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder;
use App\Services\Drafts\Intelligence\DraftIntelligenceDeltaService;
use App\Services\Drafts\Intelligence\DraftRecommendationEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public string $draftId,
        public bool $force = false,
        public ?string $userId = null,
        public ?string $operationKey = null,
    ) {
        $this->operationKey ??= (string) Str::uuid();
        $this->onQueue((string) config('draft_intelligence.queue', 'ai-low'));
    }

    public function handle(
        DraftIntelligenceService $intelligence,
        DraftIntelligenceBillingService $billing,
        DraftRecommendationEngine $recommendationEngine,
        DraftImprovementHistoryBuilder $historyBuilder,
        DraftIntelligenceDeltaService $deltaService,
    ): void {
        $draft = Draft::query()->with('analysis')->find($this->draftId);
        if (! $draft || trim((string) $draft->content_html) === '') {
            return;
        }

        if (! $this->force && $intelligence->hasFreshAnalysis($draft)) {
            return;
        }

        $analysisRecord = DraftAnalysis::query()->create([
            'draft_id' => (string) $draft->id,
            'status' => DraftAnalysis::STATUS_PROCESSING,
            'suggestions' => $intelligence->emptyNormalizedPayload($draft),
            'normalized_payload' => $intelligence->emptyNormalizedPayload($draft),
            'prompt_version' => DraftIntelligenceService::PROMPT_VERSION,
            'created_at' => now(),
        ]);

        try {
            $reservation = $billing->reserveForAnalysis(
                draft: $draft,
                userId: $this->userId,
                suffix: $this->force
                    ? 'manual:' . $this->operationKey
                    : 'auto:' . sha1(implode('|', [
                        (string) $draft->title,
                        (string) $draft->seo_title,
                        (string) $draft->seo_meta_description,
                        (string) $draft->content_html,
                    ])),
            );
        } catch (InsufficientCreditsException $exception) {
            if (! $this->force) {
                return;
            }

            throw $exception;
        }

        try {
            $analysis = $intelligence->analyze($draft, $this->force);

            $analysisRecord->forceFill($analysis->toModelAttributes())->save();
            $recommendations = $recommendationEngine->generate($draft->fresh(), $analysisRecord);
            $analysisRecord->recommendations()->delete();
            $analysisRecord->recommendations()->createMany(array_map(
                static fn (array $recommendation): array => array_merge($recommendation, [
                    'draft_id' => (string) $analysisRecord->draft_id,
                ]),
                $recommendations,
            ));

            $payload = $analysisRecord->canonicalPayload();
            data_set($payload, 'top_priorities', collect($recommendations)->take(3)->values()->all());
            $analysisRecord->forceFill([
                'normalized_payload' => $payload,
                'suggestions' => $payload,
            ])->save();

            $pendingImprovement = $this->operationKey
                ? $historyBuilder->pendingForOperation($draft->fresh(), $this->operationKey)
                : null;

            if ($pendingImprovement) {
                $deltaSnapshot = $deltaService->storeForImprovement(
                    $pendingImprovement,
                    $pendingImprovement->beforeAnalysis,
                    $analysisRecord,
                );
                $historyBuilder->attachAnalysis($pendingImprovement, $analysisRecord, $deltaSnapshot);
            }

            $billing->capture($reservation, $draft, [
                'analysis_id' => (string) $analysisRecord->id,
                'analysis_model' => $analysisRecord->analysis_model,
                'tokens_used' => (int) ($analysisRecord->tokens_used ?? 0),
            ], $this->userId);
        } catch (Throwable $exception) {
            $analysisRecord->forceFill([
                'status' => DraftAnalysis::STATUS_FAILED,
                'normalized_payload' => $analysisRecord->normalized_payload ?: $intelligence->emptyNormalizedPayload($draft),
                'suggestions' => $analysisRecord->suggestions ?: $intelligence->emptyNormalizedPayload($draft),
                'validation_errors' => [$exception->getMessage()],
            ])->save();

            $billing->release($reservation, $draft, 'draft_analysis_failed', [
                'error' => $exception->getMessage(),
            ], $this->userId);

            throw $exception;
        }
    }
}
