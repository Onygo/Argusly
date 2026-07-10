<?php

namespace App\Data\Reporting;

class MetricDefinition
{
    /**
     * @param  array<int, string>  $requiredInputs
     * @param  array<int, string>  $supportedDimensions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $formula,
        public readonly array $requiredInputs,
        public readonly array $supportedDimensions,
        public readonly string $currencyBehavior,
        public readonly string $nullZeroBehavior,
        public readonly bool $requiresAttribution,
        public readonly string $freshnessRequirement,
        public readonly string $currencyDependency = 'none',
        public readonly string $aggregationRule = 'sum',
        public readonly string $mixedCurrencyBehavior = 'not_applicable',
        public readonly string $reportingCurrencyRequirement = 'not_required',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'formula' => $this->formula,
            'required_inputs' => $this->requiredInputs,
            'supported_dimensions' => $this->supportedDimensions,
            'currency_behavior' => $this->currencyBehavior,
            'null_zero_behavior' => $this->nullZeroBehavior,
            'requires_attribution' => $this->requiresAttribution,
            'freshness_requirement' => $this->freshnessRequirement,
            'currency_dependency' => $this->currencyDependency,
            'aggregation_rule' => $this->aggregationRule,
            'mixed_currency_behavior' => $this->mixedCurrencyBehavior,
            'reporting_currency_requirement' => $this->reportingCurrencyRequirement,
        ];
    }
}
