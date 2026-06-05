<?php

namespace App\Services\DraftComparison;

use App\Models\Brief;
use Illuminate\Support\Str;

class DraftScoreExpectationResolver
{
    /**
     * @var array<string,int>
     */
    private const STRATEGY_FIT_WEIGHTS = [
        'cta_strength' => 40,
        'readability_score' => 35,
        'structure_quality' => 25,
    ];

    /**
     * @return array{
     *   content_type_key:string,
     *   content_type_label:string,
     *   audience_key:string,
     *   audience_label:string,
     *   funnel_stage_key:string,
     *   funnel_stage_label:string,
     *   search_intent_key:string,
     *   search_intent_label:string,
     *   is_informational_utility_content:bool
     * }
     */
    public function resolveContentProfile(Brief $brief): array
    {
        $contentType = $this->normalizeContentType((string) ($brief->content_type ?? ''));
        $audienceRaw = trim((string) ($brief->target_audience ?: $brief->audience ?: ''));
        $audience = $this->normalizeAudience($audienceRaw);
        $funnelStage = $this->normalizeFunnelStage((string) ($brief->funnel_stage ?? ''));
        $searchIntent = $this->normalizeSearchIntent((string) ($brief->search_intent ?? ''));
        $isUtilityContent = $this->isInformationalUtilityContent($contentType, $searchIntent);

        return [
            'content_type_key' => $contentType,
            'content_type_label' => $this->contentTypeLabel($contentType),
            'audience_key' => $audience,
            'audience_label' => $this->audienceLabel($audience, $audienceRaw),
            'funnel_stage_key' => $funnelStage,
            'funnel_stage_label' => $this->funnelStageLabel($funnelStage),
            'search_intent_key' => $searchIntent,
            'search_intent_label' => $this->searchIntentLabel($searchIntent),
            'is_informational_utility_content' => $isUtilityContent,
        ];
    }

    public function supportsMetric(string $metricKey): bool
    {
        return in_array($metricKey, ['cta_strength', 'readability_score', 'structure_quality'], true);
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{
     *   metric_key:string,
     *   actual_score:?float,
     *   expected_min:?float,
     *   expected_max:?float,
     *   expected_range_label:string,
     *   status_level:string,
     *   status_label:string,
     *   explanation:string,
     *   alignment_points:int,
     *   is_contextual:bool
     * }
     */
    public function interpretMetric(string $metricKey, mixed $rawScore, array $profile): array
    {
        if (! is_numeric($rawScore)) {
            return [
                'metric_key' => $metricKey,
                'actual_score' => null,
                'expected_min' => null,
                'expected_max' => null,
                'expected_range_label' => 'No score available',
                'status_level' => 'acceptable',
                'status_label' => 'Not scored yet',
                'explanation' => 'This metric is not available for this draft output yet.',
                'alignment_points' => 60,
                'is_contextual' => $this->supportsMetric($metricKey),
            ];
        }

        $score = round((float) $rawScore, 1);
        $expectation = $this->metricExpectation($metricKey, $profile);
        $status = $this->resolveStatus(
            metricKey: $metricKey,
            score: $score,
            min: (float) ($expectation['min'] ?? 0.0),
            max: (float) ($expectation['max'] ?? 100.0),
            inRangeLabel: (string) ($expectation['in_range_label'] ?? 'Good'),
            belowSlightLabel: (string) ($expectation['below_slight_label'] ?? 'Slightly below target'),
            aboveSlightLabel: (string) ($expectation['above_slight_label'] ?? 'Slightly above target'),
            belowFarLabel: (string) ($expectation['below_far_label'] ?? 'Needs improvement'),
            aboveFarLabel: (string) ($expectation['above_far_label'] ?? 'Needs improvement'),
        );

        return [
            'metric_key' => $metricKey,
            'actual_score' => $score,
            'expected_min' => (float) ($expectation['min'] ?? 0.0),
            'expected_max' => (float) ($expectation['max'] ?? 100.0),
            'expected_range_label' => (string) ($expectation['range_label'] ?? ''),
            'status_level' => (string) ($status['level'] ?? 'acceptable'),
            'status_label' => (string) ($status['label'] ?? 'Acceptable'),
            'explanation' => (string) ($expectation['context_explanation'] ?? ''),
            'alignment_points' => (int) ($status['alignment_points'] ?? 60),
            'is_contextual' => $this->supportsMetric($metricKey),
        ];
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $profile
     * @return array{
     *   score:?float,
     *   status_level:string,
     *   status_label:string,
     *   summary:string,
     *   metric_breakdown:array<int,array{metric_key:string,status_level:string,status_label:string,alignment_points:int}>
     * }
     */
    public function strategyFit(array $metrics, array $profile): array
    {
        $weightedScore = 0.0;
        $weightTotal = 0.0;
        $breakdown = [];

        foreach (self::STRATEGY_FIT_WEIGHTS as $metricKey => $weight) {
            $interpretation = $this->interpretMetric($metricKey, $metrics[$metricKey] ?? null, $profile);
            $points = (int) ($interpretation['alignment_points'] ?? 0);
            $weightedScore += ($points * $weight);
            $weightTotal += $weight;
            $breakdown[] = [
                'metric_key' => $metricKey,
                'status_level' => (string) ($interpretation['status_level'] ?? 'acceptable'),
                'status_label' => (string) ($interpretation['status_label'] ?? 'Acceptable'),
                'alignment_points' => $points,
            ];
        }

        if ($weightTotal <= 0.0) {
            return [
                'score' => null,
                'status_level' => 'acceptable',
                'status_label' => 'Insufficient context',
                'summary' => 'Not enough contextual data is available to determine strategy fit.',
                'metric_breakdown' => $breakdown,
            ];
        }

        $score = round($weightedScore / $weightTotal, 1);
        [$level, $label] = $this->fitStatusForScore($score);

        return [
            'score' => $score,
            'status_level' => $level,
            'status_label' => $label,
            'summary' => $this->fitSummaryForProfile($level, $profile),
            'metric_breakdown' => $breakdown,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     */
    public function helperTextForMetric(string $metricKey, array $profile): string
    {
        return match ($metricKey) {
            'cta_strength' => $this->ctaHelperText($profile),
            'readability_score' => 'Readability expectations depend on audience depth. Technical audiences can need more complexity.',
            'structure_quality' => 'Structure quality remains broadly important, but thresholds still adjust by content format.',
            default => 'Use this metric as a directional signal, not an absolute grade.',
        };
    }

    /**
     * @param array<string,mixed> $profile
     * @return array{
     *   min:float,
     *   max:float,
     *   range_label:string,
     *   context_explanation:string,
     *   in_range_label:string,
     *   below_slight_label:string,
     *   above_slight_label:string,
     *   below_far_label:string,
     *   above_far_label:string
     * }
     */
    private function metricExpectation(string $metricKey, array $profile): array
    {
        return match ($metricKey) {
            'cta_strength' => $this->ctaExpectation($profile),
            'readability_score' => $this->readabilityExpectation($profile),
            'structure_quality' => $this->structureExpectation($profile),
            default => [
                'min' => 45.0,
                'max' => 80.0,
                'range_label' => '45-80 reference range',
                'context_explanation' => 'This metric is shown as a directional reference score.',
                'in_range_label' => 'Good',
                'below_slight_label' => 'Slightly below target',
                'above_slight_label' => 'Slightly above target',
                'below_far_label' => 'Needs improvement',
                'above_far_label' => 'Misaligned',
            ],
        };
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function ctaExpectation(array $profile): array
    {
        $funnelStage = (string) ($profile['funnel_stage_key'] ?? 'consideration');
        $isUtilityContent = (bool) ($profile['is_informational_utility_content'] ?? false);

        if ($isUtilityContent) {
            return [
                'min' => 0.0,
                'max' => 35.0,
                'range_label' => '0-35 soft CTA target',
                'context_explanation' => 'Utility and informational content should stay educational first, with only soft conversion pressure.',
                'in_range_label' => 'Correct for utility content',
                'below_slight_label' => 'Ideal for context',
                'above_slight_label' => 'Slightly too strong for utility content',
                'below_far_label' => 'Acceptable',
                'above_far_label' => 'Misaligned with informational goal',
            ];
        }

        return match ($funnelStage) {
            'awareness' => [
                'min' => 10.0,
                'max' => 30.0,
                'range_label' => '10-30 expected for awareness',
                'context_explanation' => 'Awareness content should prioritize clarity and trust-building over strong conversion pressure.',
                'in_range_label' => 'Correct for funnel stage',
                'below_slight_label' => 'Ideal for awareness',
                'above_slight_label' => 'Slightly too strong for this stage',
                'below_far_label' => 'Acceptable for awareness',
                'above_far_label' => 'Misaligned with awareness goal',
            ],
            'decision' => [
                'min' => 65.0,
                'max' => 90.0,
                'range_label' => '65-90 expected for decision',
                'context_explanation' => 'Decision-stage content benefits from clear and explicit next-step calls to action.',
                'in_range_label' => 'Ideal for conversion stage',
                'below_slight_label' => 'Slightly below decision target',
                'above_slight_label' => 'Strong and conversion-focused',
                'below_far_label' => 'Needs stronger CTA',
                'above_far_label' => 'Too forceful',
            ],
            default => [
                'min' => 35.0,
                'max' => 65.0,
                'range_label' => '35-65 expected for consideration',
                'context_explanation' => 'Consideration content should balance educational value with visible but not aggressive conversion intent.',
                'in_range_label' => 'Good for audience',
                'below_slight_label' => 'Slightly below target',
                'above_slight_label' => 'Slightly too strong for this stage',
                'below_far_label' => 'Needs stronger CTA',
                'above_far_label' => 'Misaligned with stage',
            ],
        };
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function readabilityExpectation(array $profile): array
    {
        $audience = (string) ($profile['audience_key'] ?? 'general');
        $contentType = (string) ($profile['content_type_key'] ?? 'blog');

        $isTechnical = $audience === 'technical'
            || in_array($contentType, ['documentation', 'kb_article', 'technical_guide'], true);

        if ($isTechnical) {
            return [
                'min' => 30.0,
                'max' => 55.0,
                'range_label' => '30-55 expected for technical audience',
                'context_explanation' => 'Technical readers can prefer precise, denser wording over simplified language.',
                'in_range_label' => 'Good for technical audience',
                'below_slight_label' => 'Dense but still acceptable',
                'above_slight_label' => 'Potentially too simplified',
                'below_far_label' => 'Needs clarity improvements',
                'above_far_label' => 'Too broad for technical depth',
            ];
        }

        if (in_array($contentType, ['landing', 'email'], true)) {
            return [
                'min' => 65.0,
                'max' => 90.0,
                'range_label' => '65-90 expected for conversion content',
                'context_explanation' => 'Landing and campaign copy should stay highly scannable and easy to read quickly.',
                'in_range_label' => 'Excellent',
                'below_slight_label' => 'Slightly dense for this format',
                'above_slight_label' => 'Highly readable',
                'below_far_label' => 'Needs readability improvements',
                'above_far_label' => 'Good',
            ];
        }

        return [
            'min' => 60.0,
            'max' => 85.0,
            'range_label' => '60-85 expected for broad audience',
            'context_explanation' => 'General business and marketing audiences typically perform better with highly readable content.',
            'in_range_label' => 'Ideal for context',
            'below_slight_label' => 'Slightly below target',
            'above_slight_label' => 'Very readable',
            'below_far_label' => 'Needs readability work',
            'above_far_label' => 'Good',
        ];
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function structureExpectation(array $profile): array
    {
        $contentType = (string) ($profile['content_type_key'] ?? 'blog');

        if (in_array($contentType, ['landing', 'email'], true)) {
            return [
                'min' => 70.0,
                'max' => 95.0,
                'range_label' => '70-95 expected for this format',
                'context_explanation' => 'Conversion-oriented formats rely on strong hierarchy and clear section flow.',
                'in_range_label' => 'Excellent',
                'below_slight_label' => 'Acceptable',
                'above_slight_label' => 'Slightly above target',
                'below_far_label' => 'Needs structural improvements',
                'above_far_label' => 'Slightly above target',
            ];
        }

        return [
            'min' => 65.0,
            'max' => 92.0,
            'range_label' => '65-92 expected baseline',
            'context_explanation' => 'Structure quality is a broad quality signal and should remain medium to high across content types.',
            'in_range_label' => 'Good',
            'below_slight_label' => 'Acceptable',
            'above_slight_label' => 'Slightly above target',
            'below_far_label' => 'Needs improvement',
            'above_far_label' => 'Slightly above target',
        ];
    }

    /**
     * @return array{level:string,label:string,alignment_points:int}
     */
    private function resolveStatus(
        string $metricKey,
        float $score,
        float $min,
        float $max,
        string $inRangeLabel,
        string $belowSlightLabel,
        string $aboveSlightLabel,
        string $belowFarLabel,
        string $aboveFarLabel,
    ): array {
        $deltaBelow = max(0.0, $min - $score);
        $deltaAbove = max(0.0, $score - $max);
        $span = max(1.0, $max - $min);

        if ($deltaBelow === 0.0 && $deltaAbove === 0.0) {
            $distanceFromCenter = abs($score - ($min + ($span / 2)));
            if ($distanceFromCenter <= ($span * 0.16)) {
                return ['level' => 'ideal_for_context', 'label' => $inRangeLabel, 'alignment_points' => 95];
            }

            $level = $metricKey === 'structure_quality' && $score >= ($max - 3.0) ? 'excellent' : 'good';
            $label = $level === 'excellent' ? 'Excellent' : $inRangeLabel;
            $points = $level === 'excellent' ? 100 : 84;

            return ['level' => $level, 'label' => $label, 'alignment_points' => $points];
        }

        if ($deltaBelow > 0.0) {
            if ($deltaBelow <= 6.0) {
                return ['level' => 'acceptable', 'label' => $belowSlightLabel, 'alignment_points' => 70];
            }
            if ($deltaBelow <= 16.0) {
                return ['level' => 'misaligned', 'label' => $belowFarLabel, 'alignment_points' => 45];
            }

            return ['level' => 'needs_improvement', 'label' => $belowFarLabel, 'alignment_points' => 20];
        }

        if ($deltaAbove <= 6.0) {
            return ['level' => 'acceptable', 'label' => $aboveSlightLabel, 'alignment_points' => 72];
        }
        if ($deltaAbove <= 16.0) {
            return ['level' => 'misaligned', 'label' => $aboveFarLabel, 'alignment_points' => 45];
        }

        return ['level' => 'needs_improvement', 'label' => $aboveFarLabel, 'alignment_points' => 20];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function fitStatusForScore(float $score): array
    {
        if ($score >= 92.0) {
            return ['excellent', 'Excellent'];
        }
        if ($score >= 82.0) {
            return ['ideal_for_context', 'Ideal for context'];
        }
        if ($score >= 72.0) {
            return ['good', 'Good'];
        }
        if ($score >= 60.0) {
            return ['acceptable', 'Acceptable'];
        }
        if ($score >= 42.0) {
            return ['misaligned', 'Misaligned'];
        }

        return ['needs_improvement', 'Needs improvement'];
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function fitSummaryForProfile(string $level, array $profile): string
    {
        $funnel = (string) ($profile['funnel_stage_label'] ?? 'your strategy');
        $audience = (string) ($profile['audience_label'] ?? 'selected audience');

        return match ($level) {
            'excellent' => sprintf('This draft is strongly aligned with %s goals for %s.', $funnel, $audience),
            'ideal_for_context' => sprintf('This draft fits the %s strategy and audience profile well.', $funnel),
            'good' => 'This draft is mostly aligned, with room for a few strategic refinements.',
            'acceptable' => 'This draft is workable but has a few context mismatches.',
            'misaligned' => 'This draft shows notable misalignment with the intended strategy profile.',
            default => 'This draft needs strategic rewrites to match the intended context.',
        };
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function ctaHelperText(array $profile): string
    {
        $funnelStage = (string) ($profile['funnel_stage_key'] ?? 'consideration');

        if ((bool) ($profile['is_informational_utility_content'] ?? false)) {
            return 'Utility and informational content should keep CTA pressure light. Hard selling can reduce trust.';
        }

        return match ($funnelStage) {
            'awareness' => 'Low CTA strength is expected for Awareness stage content. Strong selling is usually premature.',
            'decision' => 'Decision-stage content should include clear next-step CTAs to convert qualified intent.',
            default => 'Consideration-stage content should balance educational depth with visible but moderate CTA intent.',
        };
    }

    private function normalizeContentType(string $value): string
    {
        $normalized = Str::of($value)->trim()->lower()->replace(' ', '_')->value();

        return match ($normalized) {
            'landing_page' => 'landing',
            'kb', 'knowledge_base', 'help_center', 'help', 'documentation', 'docs' => 'kb_article',
            'how_to', 'how-to', 'guide', 'tutorial', 'technical_guide' => 'technical_guide',
            '' => 'blog',
            default => $normalized,
        };
    }

    private function normalizeAudience(string $value): string
    {
        $normalized = Str::lower(trim($value));

        if ($normalized === '') {
            return 'general';
        }

        if (
            str_contains($normalized, 'cto')
            || str_contains($normalized, 'developer')
            || str_contains($normalized, 'engineer')
            || str_contains($normalized, 'technical')
        ) {
            return 'technical';
        }

        if (str_contains($normalized, 'marketing') || str_contains($normalized, 'business')) {
            return 'business';
        }

        return 'general';
    }

    private function normalizeFunnelStage(string $value): string
    {
        $normalized = Str::of($value)->trim()->lower()->replace(' ', '_')->value();

        return match ($normalized) {
            'awareness', 'consideration', 'decision', 'retention' => $normalized,
            default => 'consideration',
        };
    }

    private function normalizeSearchIntent(string $value): string
    {
        $normalized = Str::of($value)->trim()->lower()->replace(' ', '_')->value();

        return match ($normalized) {
            'informational', 'commercial', 'transactional', 'navigational' => $normalized,
            default => $normalized !== '' ? $normalized : 'unspecified',
        };
    }

    private function isInformationalUtilityContent(string $contentType, string $searchIntent): bool
    {
        return in_array($contentType, ['kb_article', 'technical_guide'], true)
            || $searchIntent === 'informational';
    }

    private function contentTypeLabel(string $value): string
    {
        return match ($value) {
            'kb_article' => 'Knowledge base',
            'technical_guide' => 'Technical guide',
            'landing' => 'Landing page',
            default => Str::headline(str_replace('_', ' ', $value)),
        };
    }

    private function audienceLabel(string $audience, string $rawAudience): string
    {
        if (trim($rawAudience) !== '') {
            return $rawAudience;
        }

        return match ($audience) {
            'technical' => 'Technical audience',
            'business' => 'Business audience',
            default => 'General audience',
        };
    }

    private function funnelStageLabel(string $funnelStage): string
    {
        return match ($funnelStage) {
            'awareness' => 'Awareness',
            'consideration' => 'Consideration',
            'decision' => 'Decision',
            'retention' => 'Retention',
            default => 'Consideration',
        };
    }

    private function searchIntentLabel(string $searchIntent): string
    {
        return match ($searchIntent) {
            'informational' => 'Informational',
            'commercial' => 'Commercial',
            'transactional' => 'Transactional',
            'navigational' => 'Navigational',
            'unspecified' => 'Not specified',
            default => Str::headline(str_replace('_', ' ', $searchIntent)),
        };
    }
}
