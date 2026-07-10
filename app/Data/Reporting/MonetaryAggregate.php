<?php

namespace App\Data\Reporting;

class MonetaryAggregate
{
    public const STATUS_SINGLE_CURRENCY = 'single_currency';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_MIXED_CURRENCY = 'mixed_currency';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @param  array<string, float>  $totalsByCurrency
     * @param  array<string, int|float>  $conversionCoverage
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $currency,
        public readonly ?float $amount,
        public readonly array $totalsByCurrency,
        public readonly bool $comparable,
        public readonly array $conversionCoverage,
        public readonly array $warnings = [],
    ) {}

    /**
     * @param  iterable<int, array<string, mixed>|object>  $rows
     */
    public static function fromRows(
        iterable $rows,
        string $amountKey = 'amount',
        string $currencyKey = 'currency',
        ?string $reportingAmountKey = 'reporting_amount',
        ?string $reportingCurrencyKey = 'reporting_currency',
    ): self {
        $originalTotals = [];
        $reportingTotals = [];
        $totalRows = 0;
        $convertedRows = 0;
        $warnings = [];

        foreach ($rows as $row) {
            $amount = self::numericValue(self::value($row, $amountKey));

            if ($amount === null) {
                continue;
            }

            $totalRows++;
            $currency = self::normalizeCurrency(self::value($row, $currencyKey)) ?? 'unknown';
            $originalTotals[$currency] = round(($originalTotals[$currency] ?? 0.0) + $amount, 6);

            $reportingAmount = $reportingAmountKey ? self::numericValue(self::value($row, $reportingAmountKey)) : null;
            $reportingCurrency = $reportingCurrencyKey ? self::normalizeCurrency(self::value($row, $reportingCurrencyKey)) : null;

            if ($reportingAmount !== null && $reportingCurrency !== null) {
                $convertedRows++;
                $reportingTotals[$reportingCurrency] = round(($reportingTotals[$reportingCurrency] ?? 0.0) + $reportingAmount, 6);
            }
        }

        ksort($originalTotals);
        ksort($reportingTotals);

        $coverage = self::coverage($totalRows, $convertedRows);

        if ($totalRows === 0) {
            return self::unavailable(['No monetary rows are available.'], $coverage);
        }

        $knownOriginalCurrencies = array_values(array_filter(
            array_keys($originalTotals),
            fn (string $currency): bool => $currency !== 'unknown',
        ));
        $hasUnknownCurrency = array_key_exists('unknown', $originalTotals);

        if (count($knownOriginalCurrencies) === 1 && ! $hasUnknownCurrency) {
            $currency = $knownOriginalCurrencies[0];

            return new self(
                self::STATUS_SINGLE_CURRENCY,
                $currency,
                $originalTotals[$currency],
                $originalTotals,
                true,
                $coverage,
                [],
            );
        }

        if ($convertedRows === $totalRows && count($reportingTotals) === 1) {
            $currency = array_key_first($reportingTotals);

            return new self(
                self::STATUS_CONVERTED,
                $currency,
                $reportingTotals[$currency],
                $originalTotals,
                true,
                $coverage,
                [],
            );
        }

        if ($hasUnknownCurrency) {
            $warnings[] = 'Some monetary rows are missing original currency.';
        }

        if ($convertedRows > 0 && $convertedRows < $totalRows) {
            $warnings[] = 'Reporting-currency conversion coverage is incomplete.';
        }

        if (count($knownOriginalCurrencies) === 0) {
            $warnings[] = 'Original currency is unavailable for monetary rows.';

            return new self(
                self::STATUS_UNAVAILABLE,
                null,
                null,
                $originalTotals,
                false,
                $coverage,
                array_values(array_unique($warnings)),
            );
        }

        $warnings[] = 'Monetary rows include multiple original currencies and cannot be combined.';

        return new self(
            self::STATUS_MIXED_CURRENCY,
            null,
            null,
            $originalTotals,
            false,
            $coverage,
            array_values(array_unique($warnings)),
        );
    }

    /**
     * @param  array<string, int|float>|null  $coverage
     */
    public static function unavailable(array $warnings = [], ?array $coverage = null): self
    {
        return new self(
            self::STATUS_UNAVAILABLE,
            null,
            null,
            [],
            false,
            $coverage ?? self::coverage(0, 0),
            $warnings,
        );
    }

    public static function ratio(self $money, float|int $denominator, string $zeroWarning, float $multiplier = 1.0): self
    {
        if ((float) $denominator === 0.0) {
            return self::unavailable([$zeroWarning], $money->conversionCoverage);
        }

        if (! $money->comparable || $money->amount === null) {
            return self::unavailable(
                array_values(array_unique(array_merge(
                    $money->warnings,
                    ['Monetary value is not comparable.'],
                ))),
                $money->conversionCoverage,
            );
        }

        return new self(
            $money->status,
            $money->currency,
            round(($money->amount * $multiplier) / (float) $denominator, 6),
            [],
            true,
            $money->conversionCoverage,
            $money->warnings,
        );
    }

    public static function roas(self $revenue, self $spend): self
    {
        if (! $revenue->comparable || ! $spend->comparable || $revenue->amount === null || $spend->amount === null) {
            return self::unavailable(array_values(array_unique(array_merge(
                $revenue->warnings,
                $spend->warnings,
                ['Revenue and spend currencies are not comparable.'],
            ))));
        }

        if ($spend->amount === 0.0) {
            return self::unavailable(['Spend is zero.'], $spend->conversionCoverage);
        }

        if ($revenue->currency !== $spend->currency) {
            return self::unavailable(['Revenue and spend currencies differ.']);
        }

        return new self(
            $revenue->status === self::STATUS_CONVERTED || $spend->status === self::STATUS_CONVERTED
                ? self::STATUS_CONVERTED
                : self::STATUS_SINGLE_CURRENCY,
            $revenue->currency,
            round($revenue->amount / $spend->amount, 6),
            [],
            true,
            $spend->conversionCoverage,
            [],
        );
    }

    public function amountIfComparable(): ?float
    {
        return $this->comparable ? $this->amount : null;
    }

    /**
     * @return array<int, string>
     */
    public function currenciesRepresented(): array
    {
        return array_values(array_filter(
            array_keys($this->totalsByCurrency),
            fn (string $currency): bool => $currency !== 'unknown',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'totals_by_currency' => $this->totalsByCurrency,
            'comparable' => $this->comparable,
            'conversion_coverage' => $this->conversionCoverage,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private static function coverage(int $totalRows, int $convertedRows): array
    {
        return [
            'total_rows' => $totalRows,
            'converted_rows' => $convertedRows,
            'missing_rows' => max(0, $totalRows - $convertedRows),
            'ratio' => $totalRows > 0 ? round($convertedRows / $totalRows, 4) : 0.0,
        ];
    }

    private static function value(array|object $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row->{$key} ?? null;
    }

    private static function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function normalizeCurrency(mixed $currency): ?string
    {
        $currency = strtoupper(trim((string) $currency));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : null;
    }
}
