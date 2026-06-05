<?php

namespace App\Services\Credits;

class GenerationPricing
{
    public const TYPE_ARTICLE = 'article';

    public function normalizeRequestedMaxOutputTokens(string $generationType, ?int $requested): int
    {
        $generationType = $this->normalizeType($generationType);
        $baseline = $this->baselineOutputTokens($generationType);
        $max = $this->maxOutputTokens($generationType);

        $value = $requested ?? $baseline;

        return max($baseline, min($max, $value));
    }

    public function requiredCredits(string $generationType, ?int $requestedMaxOutputTokens): int
    {
        $generationType = $this->normalizeType($generationType);
        $requested = $this->normalizeRequestedMaxOutputTokens($generationType, $requestedMaxOutputTokens);

        $baselineTokens = $this->baselineOutputTokens($generationType);
        $baselineCredits = $this->baselineCredits($generationType);
        $stepTokens = max(1, $this->stepTokens($generationType));
        $stepCredits = max(0, $this->stepCredits($generationType));
        $maxCredits = max($baselineCredits, $this->maxCredits($generationType));

        $extraTokens = max(0, $requested - $baselineTokens);
        $extraSteps = (int) ceil($extraTokens / $stepTokens);
        $required = $baselineCredits + ($extraSteps * $stepCredits);

        return min($maxCredits, max($baselineCredits, $required));
    }

    /**
     * @return array{standard:int,long:int,max:int}
     */
    public function outputTokenOptions(string $generationType): array
    {
        $generationType = $this->normalizeType($generationType);
        $baseline = $this->baselineOutputTokens($generationType);
        $long = min($this->maxOutputTokens($generationType), (int) config('credits.generation_pricing.' . $generationType . '.ui_long_output_tokens', $baseline));

        return [
            'standard' => $baseline,
            'long' => max($baseline, $long),
            'max' => $this->maxOutputTokens($generationType),
        ];
    }

    public function modelOutputCap(?string $provider, ?string $model): int
    {
        $providerKey = strtolower(trim((string) $provider));
        $modelKey = trim((string) $model);

        if ($providerKey === '') {
            return 0;
        }

        $providerConfig = (array) config('credits.llm_output_caps.' . $providerKey, []);
        if ($modelKey !== '' && isset($providerConfig[$modelKey])) {
            return max(0, (int) $providerConfig[$modelKey]);
        }

        return max(0, (int) ($providerConfig['default'] ?? 0));
    }

    private function normalizeType(string $generationType): string
    {
        $key = strtolower(trim($generationType));

        return match ($key) {
            'kb_article', 'article', 'draft' => self::TYPE_ARTICLE,
            default => self::TYPE_ARTICLE,
        };
    }

    private function baselineOutputTokens(string $generationType): int
    {
        return max(1, (int) config('credits.generation_pricing.' . $generationType . '.baseline_output_tokens', 8000));
    }

    private function baselineCredits(string $generationType): int
    {
        return max(1, (int) config('credits.generation_pricing.' . $generationType . '.baseline_credits', 10));
    }

    private function stepTokens(string $generationType): int
    {
        return max(1, (int) config('credits.generation_pricing.' . $generationType . '.step_tokens', 2000));
    }

    private function stepCredits(string $generationType): int
    {
        return max(0, (int) config('credits.generation_pricing.' . $generationType . '.step_credits', 2));
    }

    private function maxCredits(string $generationType): int
    {
        return max(1, (int) config('credits.generation_pricing.' . $generationType . '.max_credits', 16));
    }

    private function maxOutputTokens(string $generationType): int
    {
        return max(
            $this->baselineOutputTokens($generationType),
            (int) config('credits.generation_pricing.' . $generationType . '.max_output_tokens', 14000)
        );
    }
}
