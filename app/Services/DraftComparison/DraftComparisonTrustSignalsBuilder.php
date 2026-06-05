<?php

namespace App\Services\DraftComparison;

use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;
use Illuminate\Support\Arr;

class DraftComparisonTrustSignalsBuilder
{
    private const TRUST_VERSION = 'draft_compare_trust_v1';

    public function __construct(
        private readonly DraftComparisonScoringService $scoringService,
    ) {}

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    public function build(DraftComparison $comparison, array $summary = []): array
    {
        $comparison->loadMissing([
            'brief',
            'variants.scores',
            'variants.draft',
        ]);

        $variantRows = $comparison->variants
            ->sortBy('sort_order')
            ->values()
            ->map(fn (DraftComparisonVariant $variant): array => $this->variantPayload($variant))
            ->all();

        $sharedInputHashes = collect($variantRows)
            ->map(fn (array $row): string => trim((string) data_get($row, 'prompt_snapshot_summary.shared_inputs_hash', '')))
            ->filter()
            ->unique()
            ->values();

        $baselineHash = trim((string) data_get($summary, 'prompt_audit.shared_inputs_hash', ''));
        $allPromptSnapshotsCaptured = count($variantRows) > 0
            && collect($variantRows)->every(fn (array $row): bool => ! empty($row['prompt_snapshot_summary']));
        $promptHashesConsistent = $sharedInputHashes->count() <= 1
            && ($baselineHash === '' || $sharedInputHashes->isEmpty() || $sharedInputHashes->first() === $baselineHash);

        $inputTokens = collect($variantRows)->sum(fn (array $row): int => (int) data_get($row, 'usage.input_tokens', 0));
        $outputTokens = collect($variantRows)->sum(fn (array $row): int => (int) data_get($row, 'usage.output_tokens', 0));
        $creditCost = collect($variantRows)->sum(fn (array $row): int => (int) data_get($row, 'usage.credit_cost', 0));

        return [
            'version' => self::TRUST_VERSION,
            'generated_at' => now()->toIso8601String(),
            'compare_scope' => (string) data_get($comparison->meta, 'compare_scope', DraftComparisonService::COMPARE_SCOPE_FULL_DRAFT),
            'recommendation_explanation' => (string) data_get($summary, 'recommendation.why_it_won', ''),
            'prompt_consistency' => [
                'baseline_shared_inputs_hash' => $baselineHash !== '' ? $baselineHash : null,
                'unique_shared_inputs_hashes' => $sharedInputHashes->values()->all(),
                'all_prompt_snapshots_captured' => $allPromptSnapshotsCaptured,
                'hash_consistent' => $promptHashesConsistent,
            ],
            'usage_summary' => [
                'input_tokens' => max(0, (int) $inputTokens),
                'output_tokens' => max(0, (int) $outputTokens),
                'total_tokens' => max(0, (int) ($inputTokens + $outputTokens)),
                'credit_cost' => max(0, (int) $creditCost),
            ],
            'status_summary' => [
                'variant_total' => count($variantRows),
                'variant_completed' => count(array_filter($variantRows, fn (array $row): bool => (string) ($row['status'] ?? '') === DraftComparisonVariant::STATUS_COMPLETED)),
                'variant_failed' => count(array_filter($variantRows, fn (array $row): bool => in_array((string) ($row['status'] ?? ''), [DraftComparisonVariant::STATUS_FAILED, DraftComparisonVariant::STATUS_CANCELLED], true))),
            ],
            'metric_source_legend' => $this->scoringService->metricSourceLegend(),
            'variants' => $variantRows,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function variantPayload(DraftComparisonVariant $variant): array
    {
        $generation = (array) data_get($variant->draft?->meta, 'generation', []);
        $snapshot = is_array($variant->prompt_snapshot_json) ? $variant->prompt_snapshot_json : [];

        return [
            'variant_id' => (string) $variant->id,
            'status' => (string) $variant->status,
            'provider_model' => [
                'requested_provider' => (string) $variant->provider_key,
                'requested_model' => (string) $variant->model_key,
                'actual_provider' => trim((string) data_get($generation, 'provider', '')) !== ''
                    ? (string) data_get($generation, 'provider')
                    : null,
                'actual_model' => trim((string) data_get($generation, 'model_used', data_get($generation, 'model', ''))) !== ''
                    ? (string) data_get($generation, 'model_used', data_get($generation, 'model', ''))
                    : null,
            ],
            'prompt_snapshot_summary' => $this->promptSnapshotSummary($snapshot),
            'generation_timestamps' => [
                'started_at' => $variant->started_at?->toIso8601String(),
                'completed_at' => $variant->completed_at?->toIso8601String(),
            ],
            'usage' => [
                'input_tokens' => max(0, (int) ($variant->input_tokens ?? 0)),
                'output_tokens' => max(0, (int) ($variant->output_tokens ?? 0)),
                'total_tokens' => max(0, (int) (($variant->input_tokens ?? 0) + ($variant->output_tokens ?? 0))),
                'credit_cost' => max(0, (int) ($variant->credit_cost ?? 0)),
                'latency_ms' => max(0, (int) ($variant->latency_ms ?? 0)),
            ],
            'score_details' => $variant->scores
                ->mapWithKeys(static function ($score): array {
                    return [
                        (string) $score->metric_key => array_filter([
                            'label' => (string) $score->metric_label,
                            'group' => $score->metric_group,
                            'source_type' => $score->source_type,
                            'numeric_score' => is_numeric($score->numeric_score) ? round((float) $score->numeric_score, 3) : null,
                            'text_score' => $score->text_score,
                            'explanation' => $score->explanation,
                        ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                    ];
                })
                ->all(),
            'error_message' => $variant->error_message !== null && trim($variant->error_message) !== ''
                ? $variant->error_message
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function promptSnapshotSummary(array $snapshot): array
    {
        if ($snapshot === []) {
            return [];
        }

        $secondaryKeywords = Arr::wrap(data_get($snapshot, 'shared_inputs.keywords.secondary', []));
        $keyPoints = Arr::wrap(data_get($snapshot, 'shared_inputs.content_goals.key_points', []));
        $structure = Arr::wrap(data_get($snapshot, 'shared_inputs.structure_instructions', []));

        return array_filter([
            'captured_at' => data_get($snapshot, 'captured_at'),
            'shared_inputs_hash' => data_get($snapshot, 'shared_inputs_hash'),
            'brief_id' => data_get($snapshot, 'shared_inputs.brief.id'),
            'brief_title' => data_get($snapshot, 'shared_inputs.brief.title'),
            'language' => data_get($snapshot, 'shared_inputs.brief.language'),
            'target_audience' => data_get($snapshot, 'shared_inputs.brief.target_audience'),
            'tone' => data_get($snapshot, 'shared_inputs.voice.tone'),
            'primary_keyword' => data_get($snapshot, 'shared_inputs.keywords.primary'),
            'secondary_keyword_count' => count(array_filter(array_map(static fn (mixed $keyword): string => trim((string) $keyword), $secondaryKeywords))),
            'key_point_count' => count(array_filter(array_map(static fn (mixed $point): string => trim((string) $point), $keyPoints))),
            'structure_instruction_count' => count(array_filter(array_map(static fn (mixed $part): string => trim((string) $part), $structure))),
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
