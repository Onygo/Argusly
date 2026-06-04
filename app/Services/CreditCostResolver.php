<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\CreditCostCatalog;
use App\Models\CreditCostOverride;
use InvalidArgumentException;

class CreditCostResolver
{
    public function __construct(private readonly DomainEventService $events) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, requested_code: string, cost: int, source: string, catalog: CreditCostCatalog}
     */
    public function resolveCost(string $code, ?Account $account = null, ?Brand $brand = null, array $context = []): array
    {
        return $this->resolve($code, $account, $brand, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, requested_code: string, cost: int, source: string, catalog: CreditCostCatalog}
     */
    public function resolveCostForAccount(Account $account, string $code, array $context = []): array
    {
        return $this->resolve($code, $account, null, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, requested_code: string, cost: int, source: string, catalog: CreditCostCatalog}
     */
    public function resolveCostForBrand(Account $account, Brand $brand, string $code, array $context = []): array
    {
        if ($brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Credit cost brand must belong to the account.');
        }

        return $this->resolve($code, $account, $brand, $context);
    }

    public function supportsOverride(string $code): bool
    {
        return CreditCostCatalog::query()
            ->where('code', $this->normalizeCode($code))
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function calculateVariableCost(CreditCostCatalog $catalog, int $baseCost, array $context = []): int
    {
        $cost = $baseCost;

        if ($catalog->cost_type !== 'variable') {
            return $this->clamp($catalog, $cost);
        }

        $rules = $catalog->metadata['variable_rules'] ?? [];

        if (($rules['status'] ?? 'planned') !== 'active') {
            return $this->clamp($catalog, $cost);
        }

        $unit = $rules['unit'] ?? null;
        $additionalCost = is_numeric($rules['additional_cost'] ?? null) ? (int) $rules['additional_cost'] : 0;

        if ($unit === '1000_words' && is_numeric($context['word_count'] ?? null)) {
            $cost += (int) ceil(((int) $context['word_count']) / 1000) * $additionalCost;
        }

        if ($unit === 'provider' && is_numeric($context['provider_count'] ?? null)) {
            $cost += max(0, ((int) $context['provider_count']) - 1) * $additionalCost;
        }

        if ($unit === 'llm_usage' && is_numeric($context['llm_credit_units'] ?? null)) {
            $cost += ((int) $context['llm_credit_units']) * $additionalCost;
        }

        return $this->clamp($catalog, $cost);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{code: string, requested_code: string, cost: int, source: string, catalog: CreditCostCatalog}
     */
    private function resolve(string $requestedCode, ?Account $account, ?Brand $brand, array $context): array
    {
        $code = $this->normalizeCode($requestedCode);
        $catalog = CreditCostCatalog::query()
            ->where('code', $code)
            ->where('status', 'active')
            ->first();

        if (! $catalog) {
            throw new InvalidArgumentException("Credit cost catalog code [{$requestedCode}] is not active.");
        }

        $source = 'catalog';
        $baseCost = $catalog->default_cost;
        $override = $this->override($catalog, $account, $brand);

        if ($override) {
            $source = $override->brand_id !== null ? 'brand_override' : 'account_override';
            $baseCost = $override->override_cost;
        }

        $cost = $this->calculateVariableCost($catalog, $baseCost, $context);

        if ($account) {
            $this->events->record('CreditCostResolved', $account, $brand, null, null, [
                'requested_code' => $requestedCode,
                'catalog_code' => $code,
                'cost' => $cost,
                'source' => $source,
            ], dispatch: false);
        }

        return [
            'code' => $code,
            'requested_code' => $requestedCode,
            'cost' => $cost,
            'source' => $source,
            'catalog' => $catalog,
        ];
    }

    private function override(CreditCostCatalog $catalog, ?Account $account, ?Brand $brand): ?CreditCostOverride
    {
        if ($account && $brand) {
            $brandOverride = CreditCostOverride::query()
                ->where('credit_cost_catalog_id', $catalog->id)
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->where('status', 'active')
                ->first();

            if ($brandOverride) {
                return $brandOverride;
            }
        }

        if (! $account) {
            return null;
        }

        return CreditCostOverride::query()
            ->where('credit_cost_catalog_id', $catalog->id)
            ->where('account_id', $account->id)
            ->whereNull('brand_id')
            ->where('status', 'active')
            ->first();
    }

    private function normalizeCode(string $code): string
    {
        $alias = config("credits.aliases.{$code}");

        return is_string($alias) && $alias !== '' ? $alias : $code;
    }

    private function clamp(CreditCostCatalog $catalog, int $cost): int
    {
        if ($catalog->minimum_cost !== null) {
            $cost = max($cost, $catalog->minimum_cost);
        }

        if ($catalog->maximum_cost !== null) {
            $cost = min($cost, $catalog->maximum_cost);
        }

        return max(0, $cost);
    }
}
