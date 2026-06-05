<?php

namespace App\Services\Drafts\Intelligence;

use App\Models\DraftAnalysis;
use App\Models\DraftImprovementResult;
use App\Models\DraftIntelligenceDelta;
use Illuminate\Support\Str;

class DraftIntelligenceDeltaService
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function storeForImprovement(
        DraftImprovementResult $improvementResult,
        ?DraftAnalysis $beforeAnalysis,
        DraftAnalysis $afterAnalysis,
    ): array {
        $metrics = [
            'seo' => [$beforeAnalysis?->seo_score, $afterAnalysis->seo_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.seo.explanation', '')],
            'readability' => [$beforeAnalysis?->readability_score, $afterAnalysis->readability_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.readability.explanation', '')],
            'cta' => [$beforeAnalysis?->cta_score, $afterAnalysis->cta_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.cta.explanation', '')],
            'headings' => [$beforeAnalysis?->headings_score, $afterAnalysis->headings_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.structure.explanation', '')],
            'llm_visibility' => [$beforeAnalysis?->llm_visibility_score, $afterAnalysis->llm_visibility_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.llm_visibility.explanation', '')],
            'brand_voice_fit' => [$beforeAnalysis?->brand_voice_fit_score, $afterAnalysis->brand_voice_fit_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.brand_voice_fit.explanation', '')],
            'conversion_fit' => [$beforeAnalysis?->conversion_fit_score, $afterAnalysis->conversion_fit_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.conversion_fit.explanation', '')],
            'trust_evidence' => [$beforeAnalysis?->trust_evidence_score, $afterAnalysis->trust_evidence_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.trust_evidence.explanation', '')],
            'publish_readiness' => [$beforeAnalysis?->publish_readiness_score, $afterAnalysis->publish_readiness_score, (string) data_get($afterAnalysis->canonicalPayload(), 'sections.publish_readiness.explanation', '')],
        ];

        $snapshot = [];

        foreach ($metrics as $metricKey => [$beforeScore, $afterScore, $afterExplanation]) {
            $before = $this->normalizeScore($beforeScore);
            $after = $this->normalizeScore($afterScore);
            $delta = $this->deltaValue($before, $after);

            if ($before === null && $after === null) {
                continue;
            }

            $confidence = $this->confidenceLevel($before, $after);
            $explanation = $this->deltaExplanation($metricKey, $before, $after, $delta, $afterExplanation);

            DraftIntelligenceDelta::query()->updateOrCreate(
                [
                    'draft_improvement_result_id' => (string) $improvementResult->id,
                    'metric_key' => $metricKey,
                ],
                [
                    'draft_id' => (string) $improvementResult->draft_id,
                    'before_analysis_id' => $beforeAnalysis?->id,
                    'after_analysis_id' => (string) $afterAnalysis->id,
                    'score_before' => $before,
                    'score_after' => $after,
                    'delta' => $delta,
                    'explanation' => $explanation,
                    'confidence_level' => $confidence,
                ],
            );

            $snapshot[$metricKey] = [
                'score_before' => $before,
                'score_after' => $after,
                'delta_value' => $delta,
                'delta' => $delta,
                'explanation' => $explanation,
                'confidence_level' => $confidence,
            ];
        }

        return $snapshot;
    }

    private function deltaExplanation(string $metricKey, ?int $before, ?int $after, ?int $delta, string $afterExplanation): string
    {
        $label = $this->metricLabel($metricKey);
        $tail = trim($afterExplanation);

        if ($before === null && $after !== null) {
            return trim(sprintf(
                '%s is now scored at %d. A direct before comparison was not available.%s',
                $label,
                $after,
                $tail !== '' ? ' ' . $tail : ''
            ));
        }

        if ($before !== null && $after === null) {
            return trim(sprintf(
                '%s was %d before the improvement, but the latest rescan did not produce a new score yet.%s',
                $label,
                $before,
                $tail !== '' ? ' ' . $tail : ''
            ));
        }

        if ($delta === null) {
            return trim(sprintf(
                '%s was rescanned, but a complete before/after comparison is not available yet.%s',
                $label,
                $tail !== '' ? ' ' . $tail : ''
            ));
        }

        if ($delta > 0) {
            return trim(sprintf('%s improved from %d to %d (%+d). %s', $label, $before, $after, $delta, $tail));
        }

        if ($delta < 0) {
            return trim(sprintf('%s declined from %d to %d (%+d). %s', $label, $before, $after, $delta, $tail));
        }

        return trim(sprintf('%s stayed flat at %d. %s', $label, $after, $tail));
    }

    private function metricLabel(string $metricKey): string
    {
        return match ($metricKey) {
            'seo' => 'SEO',
            'cta' => 'CTA',
            'llm_visibility' => 'LLM Visibility',
            'brand_voice_fit' => 'Brand Voice Fit',
            'conversion_fit' => 'Conversion Fit',
            'trust_evidence' => 'Trust and Evidence',
            'publish_readiness' => 'Publish Readiness',
            default => Str::headline($metricKey),
        };
    }

    private function confidenceLevel(?int $before, ?int $after): string
    {
        if ($before === null || $after === null) {
            return 'low';
        }

        $delta = abs($after - $before);

        return match (true) {
            $delta >= 15 => 'high',
            $delta >= 5 => 'medium',
            default => 'low',
        };
    }

    private function deltaValue(?int $before, ?int $after): ?int
    {
        if ($before === null || $after === null) {
            return null;
        }

        return $after - $before;
    }

    private function normalizeScore(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }
}
