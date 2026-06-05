<?php

namespace App\Services\Llm;

class LlmCostEstimator
{
    /**
     * @return array{currency:string,input_cost:float,output_cost:float,total_cost:float,input_rate_usd_per_1m:?float,output_rate_usd_per_1m:?float,exchange_rate:float}
     */
    public function estimate(string $provider, ?string $model, int $inputTokens, int $outputTokens): array
    {
        $currency = strtoupper((string) config('llm.pricing.currency', 'EUR'));
        $exchangeRate = max(0.0, (float) config('llm.pricing.usd_to_eur_rate', 0.92));
        $rates = $this->ratesFor($provider, $model);

        $inputCostUsd = ($inputTokens / 1_000_000) * (float) ($rates['input'] ?? 0);
        $outputCostUsd = ($outputTokens / 1_000_000) * (float) ($rates['output'] ?? 0);
        $multiplier = $currency === 'EUR' ? $exchangeRate : 1.0;

        $inputCost = round($inputCostUsd * $multiplier, 8);
        $outputCost = round($outputCostUsd * $multiplier, 8);

        return [
            'currency' => $currency,
            'input_cost' => $inputCost,
            'output_cost' => $outputCost,
            'total_cost' => round($inputCost + $outputCost, 8),
            'input_rate_usd_per_1m' => isset($rates['input']) ? (float) $rates['input'] : null,
            'output_rate_usd_per_1m' => isset($rates['output']) ? (float) $rates['output'] : null,
            'exchange_rate' => $exchangeRate,
        ];
    }

    /**
     * @return array{input?:float,output?:float}
     */
    private function ratesFor(string $provider, ?string $model): array
    {
        $providerRates = (array) config('llm.pricing.model_rates_usd_per_1m.' . strtolower($provider), []);
        $model = strtolower(trim((string) $model));

        if ($model !== '') {
            $candidates = collect($providerRates)
                ->except('default')
                ->keys()
                ->sortByDesc(fn (string $key): int => strlen($key));

            foreach ($candidates as $candidate) {
                $normalized = strtolower((string) $candidate);
                if ($model === $normalized || str_starts_with($model, $normalized)) {
                    return (array) $providerRates[$candidate];
                }
            }
        }

        return (array) ($providerRates['default'] ?? []);
    }
}
