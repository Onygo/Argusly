<?php

namespace App\Services\Reporting;

use App\Data\Reporting\MetricDefinition;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MetricDefinitionRegistry
{
    /**
     * @var array<string, MetricDefinition>
     */
    private array $definitions;

    public function __construct()
    {
        $dimensions = ['period', 'provider', 'channel', 'source', 'medium', 'campaign', 'currency'];

        $this->definitions = collect([
            new MetricDefinition('impressions', 'Impressions', 'Ad impressions from normalized performance rows.', 'sum(impressions)', ['connector_normalized_daily_performances.impressions'], $dimensions, 'non_currency', 'null_as_zero', false, 'latest normalization run'),
            new MetricDefinition('clicks', 'Clicks', 'Ad clicks from normalized performance rows.', 'sum(clicks)', ['connector_normalized_daily_performances.clicks'], $dimensions, 'non_currency', 'null_as_zero', false, 'latest normalization run'),
            new MetricDefinition('ctr', 'CTR', 'Click-through rate.', 'clicks / impressions', ['clicks', 'impressions'], $dimensions, 'ratio', 'zero_when_impressions_zero', false, 'latest normalization run'),
            new MetricDefinition('cpc', 'CPC', 'Average cost per click.', 'spend / clicks', ['spend', 'clicks'], $dimensions, 'currency', 'null_when_clicks_zero', false, 'latest normalization run', 'spend_currency', 'ratio', 'unavailable_when_mixed', 'single_currency_or_full_reporting_currency'),
            new MetricDefinition('cpm', 'CPM', 'Average cost per thousand impressions.', '(spend / impressions) * 1000', ['spend', 'impressions'], $dimensions, 'currency', 'null_when_impressions_zero', false, 'latest normalization run', 'spend_currency', 'ratio', 'unavailable_when_mixed', 'single_currency_or_full_reporting_currency'),
            new MetricDefinition('spend', 'Spend', 'Marketing spend from normalized performance cost.', 'sum(original_cost)', ['connector_normalized_daily_performances.original_cost', 'connector_normalized_daily_performances.original_currency'], $dimensions, 'source_currency', 'unavailable_when_currency_unknown', false, 'latest normalization run', 'original_currency', 'sum', 'partition_or_convert', 'single_currency_or_full_reporting_currency'),
            new MetricDefinition('leads', 'Leads', 'CRM contacts with privacy-safe keys.', 'count(distinct connector_normalized_crm_contacts.id)', ['connector_normalized_crm_contacts.id'], ['period', 'provider'], 'non_currency', 'null_as_zero', false, 'latest CRM normalization run'),
            new MetricDefinition('opportunities', 'Opportunities', 'CRM deals that represent pipeline opportunities.', 'count(distinct connector_normalized_crm_deals.id)', ['connector_normalized_crm_deals.id'], ['period', 'provider', 'pipeline', 'stage', 'currency'], 'non_currency', 'null_as_zero', false, 'latest CRM normalization run'),
            new MetricDefinition('conversions', 'Conversions', 'Attribution conversion count.', 'count(distinct attribution_conversions.id)', ['attribution_conversions.id'], ['period', 'conversion_type', 'currency'], 'non_currency', 'null_as_zero', true, 'latest attribution run'),
            new MetricDefinition('pipeline_value', 'Pipeline value', 'Open or active deal value.', 'sum(open deal amount)', ['connector_normalized_crm_deals.amount', 'connector_normalized_crm_deals.currency'], ['period', 'provider', 'pipeline', 'stage', 'currency'], 'source_currency', 'unavailable_when_currency_unknown', false, 'latest CRM normalization run', 'original_currency', 'sum', 'partition_or_convert', 'single_currency_or_full_reporting_currency'),
            new MetricDefinition('revenue', 'Revenue', 'Won revenue from CRM and attribution conversions.', 'max(comparable won CRM revenue, comparable attributed revenue)', ['connector_normalized_crm_deals.amount', 'connector_normalized_crm_deals.currency', 'attribution_results.value', 'attribution_results.currency'], ['period', 'provider', 'currency'], 'source_currency', 'unavailable_when_currency_unknown', false, 'latest CRM normalization run', 'original_currency', 'safe_max', 'partition_or_unavailable', 'single_currency_or_full_reporting_currency'),
            new MetricDefinition('cpl', 'CPL', 'Cost per lead.', 'spend / leads', ['spend', 'leads'], ['period', 'provider', 'channel', 'currency'], 'currency', 'null_when_leads_zero', false, 'latest normalized marketing and CRM data', 'spend_currency', 'ratio', 'unavailable_when_mixed', 'single_currency_or_full_reporting_currency'),
            new MetricDefinition('cpo', 'CPO', 'Cost per opportunity.', 'spend / opportunities', ['spend', 'opportunities'], ['period', 'provider', 'channel', 'currency'], 'currency', 'null_when_opportunities_zero', false, 'latest normalized marketing and CRM data', 'spend_currency', 'ratio', 'unavailable_when_mixed', 'single_currency_or_full_reporting_currency'),
            new MetricDefinition('cpa', 'CPA', 'Cost per acquisition/conversion.', 'spend / conversions', ['spend', 'conversions'], ['period', 'provider', 'channel', 'currency'], 'currency', 'null_when_conversions_zero', true, 'latest attribution run', 'spend_currency', 'ratio', 'unavailable_when_mixed', 'single_currency_or_full_reporting_currency'),
            new MetricDefinition('roas', 'ROAS', 'Return on ad spend.', 'revenue / spend', ['revenue', 'spend'], ['period', 'provider', 'channel', 'currency'], 'ratio', 'null_when_spend_zero', true, 'latest attribution run', 'revenue_and_spend_currency', 'ratio', 'unavailable_when_incomparable', 'same_currency_or_full_reporting_currency'),
        ])->keyBy('key')->all();
    }

    public function get(string $key): MetricDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException("Metric [{$key}] is not defined.");
    }

    /**
     * @return Collection<string, MetricDefinition>
     */
    public function all(): Collection
    {
        return collect($this->definitions);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions);
    }
}
