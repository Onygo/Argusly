<?php

namespace App\Services\HumanContent;

use App\Models\Content;
use App\Models\Draft;
use BackedEnum;
use Illuminate\Support\Arr;

class HumanContentGate
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_NEEDS_EDITORIAL_REVIEW = 'needs_editorial_review';

    private const MINIMUM_HUMAN_CONTENT_SCORE = 70;
    private const MINIMUM_EDITORIAL_QUALITY_SCORE = 65;
    private const MINIMUM_ORIGINALITY_SCORE = 65;
    private const MAXIMUM_AI_FINGERPRINT_SCORE = 45;

    /**
     * @return array{
     *   passed:bool,
     *   status:string,
     *   reasons:array<int,string>,
     *   findings:array<int,array<string,mixed>>,
     *   thresholds:array<string,int>,
     *   scores:array<string,?int>
     * }
     */
    public function evaluate(?Draft $draft, ?Content $content = null): array
    {
        $meta = is_array($draft?->meta) ? $draft->meta : [];

        return $this->evaluateMetadata($meta, $draft, $content);
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{
     *   passed:bool,
     *   status:string,
     *   reasons:array<int,string>,
     *   findings:array<int,array<string,mixed>>,
     *   thresholds:array<string,int>,
     *   scores:array<string,?int>
     * }
     */
    public function evaluateMetadata(array $meta, ?Draft $draft = null, ?Content $content = null): array
    {
        $scores = $this->scores($meta);
        $findings = $this->fingerprintFindings($meta);
        $reasons = [];

        if (! $this->shouldGate($draft, $content, $meta, $scores)) {
            return $this->result(true, [], $findings, $scores);
        }

        if (($scores['human_content_score'] ?? 0) < self::MINIMUM_HUMAN_CONTENT_SCORE) {
            $reasons[] = sprintf('Human content score is below %d.', self::MINIMUM_HUMAN_CONTENT_SCORE);
        }

        if (($scores['editorial_quality_score'] ?? 0) < self::MINIMUM_EDITORIAL_QUALITY_SCORE) {
            $reasons[] = sprintf('Editorial quality score is below %d.', self::MINIMUM_EDITORIAL_QUALITY_SCORE);
        }

        if (($scores['originality_score'] ?? 0) < self::MINIMUM_ORIGINALITY_SCORE) {
            $reasons[] = sprintf('Originality score is below %d.', self::MINIMUM_ORIGINALITY_SCORE);
        }

        if (($scores['ai_fingerprint_score'] ?? 100) > self::MAXIMUM_AI_FINGERPRINT_SCORE) {
            $reasons[] = sprintf('AI fingerprint score is above %d.', self::MAXIMUM_AI_FINGERPRINT_SCORE);
        }

        if ($this->hasSevereFingerprintFindings($findings)) {
            $reasons[] = 'Severe AI fingerprint findings need editorial review.';
        }

        if ($this->isGeneratedArticle($draft, $content, $meta) && ! $this->hasUsableEditorialPlan($meta)) {
            $reasons[] = 'Generated article is missing a usable Editorial Plan.';
        }

        return $this->result($reasons === [], $reasons, $findings, $scores);
    }

    /**
     * @return array{
     *   passed:bool,
     *   status:string,
     *   reasons:array<int,string>,
     *   findings:array<int,array<string,mixed>>,
     *   thresholds:array<string,int>,
     *   scores:array<string,?int>
     * }
     */
    public function markDraft(?Draft $draft, ?Content $content = null): array
    {
        $result = $this->evaluate($draft, $content);

        if (! $draft) {
            return $result;
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['publish_gate_status'] = $result['status'];
        $meta['human_content_gate'] = $result;

        $draft->forceFill([
            'meta' => $meta,
            'status' => $result['passed'] ? $draft->status : self::STATUS_NEEDS_EDITORIAL_REVIEW,
        ])->save();

        if ($content) {
            $content->forceFill([
                'publish_status' => $result['passed'] ? $content->publish_status : self::STATUS_NEEDS_EDITORIAL_REVIEW,
                'publish_error' => $result['passed'] ? $content->publish_error : $this->message($result),
            ])->save();
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $gate
     */
    public function message(array $gate): string
    {
        $reasons = array_values((array) ($gate['reasons'] ?? []));

        return $reasons === []
            ? 'Human Content publishing gate passed.'
            : 'Human Content publishing gate blocked auto-publication: ' . implode(' ', $reasons);
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,?int> $scores
     */
    private function shouldGate(?Draft $draft, ?Content $content, array $meta, array $scores): bool
    {
        if ($scores['human_content_score'] !== null || $scores['ai_fingerprint_score'] !== null) {
            return true;
        }

        return $this->isGeneratedArticle($draft, $content, $meta);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function isGeneratedArticle(?Draft $draft, ?Content $content, array $meta): bool
    {
        $source = strtolower(trim($this->stringValue($content?->source ?? data_get($meta, 'source', ''))));
        $originType = strtolower(trim($this->stringValue($content?->origin_type ?? '')));

        return in_array($source, ['automation', 'content_automation', 'ai', 'generated'], true)
            || str_contains($originType, 'automation')
            || data_get($meta, 'content_automation.automation_id') !== null
            || data_get($meta, 'human_content.after') !== null
            || data_get($meta, 'human_content_score_after') !== null;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function hasUsableEditorialPlan(array $meta): bool
    {
        $plan = data_get($meta, 'editorial_plan');

        return is_array($plan)
            && trim((string) data_get($plan, 'central_thesis', '')) !== ''
            && trim((string) data_get($plan, 'primary_pattern.name', '')) !== ''
            && trim((string) data_get($plan, 'primary_pattern.article_movement', '')) !== '';
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,?int>
     */
    private function scores(array $meta): array
    {
        $after = is_array(data_get($meta, 'human_content.after')) ? data_get($meta, 'human_content.after') : [];

        return [
            'human_content_score' => $this->score(data_get($meta, 'human_content_score_after', data_get($after, 'human_content_score'))),
            'editorial_quality_score' => $this->score(data_get($after, 'editorial_quality_score')),
            'originality_score' => $this->score(data_get($after, 'originality_score')),
            'ai_fingerprint_score' => $this->score(data_get($meta, 'ai_fingerprint_score_after', data_get($after, 'ai_fingerprint_score'))),
        ];
    }

    private function score(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, min(100, (int) round((float) $value))) : null;
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<int,array<string,mixed>>
     */
    private function fingerprintFindings(array $meta): array
    {
        $findings = data_get($meta, 'fingerprint_findings');

        if (! is_array($findings)) {
            $findings = data_get($meta, 'human_content.after.ai_fingerprint.findings');
        }

        return collect(is_array($findings) ? $findings : [])
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->values()
            ->all();
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     */
    private function hasSevereFingerprintFindings(array $findings): bool
    {
        return collect($findings)->contains(function (array $finding): bool {
            $severity = strtolower(trim((string) ($finding['severity'] ?? '')));
            $type = strtolower(trim((string) ($finding['type'] ?? '')));

            return in_array($severity, ['critical', 'severe', 'high'], true)
                && in_array($type, ['generic_headings', 'predictable_openings', 'chatgpt_vocabulary', 'marketing_cliches'], true);
        });
    }

    /**
     * @param array<int,string> $reasons
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,?int> $scores
     * @return array{
     *   passed:bool,
     *   status:string,
     *   reasons:array<int,string>,
     *   findings:array<int,array<string,mixed>>,
     *   thresholds:array<string,int>,
     *   scores:array<string,?int>
     * }
     */
    private function result(bool $passed, array $reasons, array $findings, array $scores): array
    {
        return [
            'passed' => $passed,
            'status' => $passed ? self::STATUS_PASSED : self::STATUS_NEEDS_EDITORIAL_REVIEW,
            'reasons' => array_values(Arr::where($reasons, fn (string $reason): bool => trim($reason) !== '')),
            'findings' => $findings,
            'thresholds' => [
                'minimum_human_content_score' => self::MINIMUM_HUMAN_CONTENT_SCORE,
                'minimum_editorial_quality_score' => self::MINIMUM_EDITORIAL_QUALITY_SCORE,
                'minimum_originality_score' => self::MINIMUM_ORIGINALITY_SCORE,
                'maximum_ai_fingerprint_score' => self::MAXIMUM_AI_FINGERPRINT_SCORE,
            ],
            'scores' => $scores,
        ];
    }
}
