<?php

namespace App\Services\Reporting;

use App\Data\Reporting\MonetaryAggregate;
use App\Models\AttributionConversion;
use App\Models\AttributionResult;
use App\Models\AttributionRun;
use App\Models\AttributionTouchpoint;
use App\Models\Connectors\Normalized\NormalizedCampaign;
use App\Models\Connectors\Normalized\NormalizedCrmContact;
use App\Models\Connectors\Normalized\NormalizedCrmDeal;
use App\Models\Connectors\Normalized\NormalizedDailyPerformance;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConnectorReportingReadService
{
    public function __construct(private readonly MetricDefinitionRegistry $metrics) {}

    public function summary(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): array
    {
        [$workspaceModel, $workspaceId, $timezone, $localStart, $localEnd, $utcStart, $utcEnd] = $this->reportingContext($workspace, $start, $end);
        $marketing = $this->marketingTotals($workspaceId, $localStart, $localEnd);
        $crm = $this->crmTotals($workspaceId, $utcStart, $utcEnd);
        $attribution = $this->attributionTotals($workspaceId, $utcStart, $utcEnd, $attributionModel);

        $spend = $marketing['spend'];
        $pipelineValue = $crm['pipeline_value'];
        $wonRevenue = $crm['won_revenue'];
        $attributionRevenue = $attribution['revenue'];
        $revenue = $this->preferredRevenue($wonRevenue, $attributionRevenue);
        $influencedPipeline = $attribution['influenced_pipeline'];
        $leads = (int) $crm['leads'];
        $opportunities = (int) $crm['opportunities'];
        $conversions = (int) $attribution['conversions'];
        $cpc = MonetaryAggregate::ratio($spend, (float) $marketing['clicks'], 'Clicks are zero.');
        $cpm = MonetaryAggregate::ratio($spend, (float) $marketing['impressions'], 'Impressions are zero.', 1000.0);
        $cpl = MonetaryAggregate::ratio($spend, $leads, 'Leads are zero.');
        $cpo = MonetaryAggregate::ratio($spend, $opportunities, 'Opportunities are zero.');
        $cpa = MonetaryAggregate::ratio($spend, $conversions, 'Conversions are zero.');
        $roas = MonetaryAggregate::roas($revenue, $spend);
        $monetary = [
            'spend' => $spend,
            'cpc' => $cpc,
            'cpm' => $cpm,
            'pipeline_value' => $pipelineValue,
            'revenue' => $revenue,
            'cpl' => $cpl,
            'cpo' => $cpo,
            'cpa' => $cpa,
            'roas' => $roas,
            'influenced_pipeline' => $influencedPipeline,
            'influenced_revenue' => $attributionRevenue,
        ];

        return [
            'period' => $this->periodPayload($localStart, $localEnd, $utcStart, $utcEnd, $timezone),
            'workspace_id' => $workspaceModel ? (string) $workspaceModel->id : $workspaceId,
            'reporting_timezone' => $timezone,
            'metrics' => [
                'impressions' => (int) $marketing['impressions'],
                'clicks' => (int) $marketing['clicks'],
                'ctr' => $this->divide((float) $marketing['clicks'], (float) $marketing['impressions']),
                'cpc' => $cpc->amountIfComparable(),
                'cpm' => $cpm->amountIfComparable(),
                'spend' => $spend->amountIfComparable(),
                'leads' => $leads,
                'opportunities' => $opportunities,
                'conversions' => $conversions,
                'pipeline_value' => $pipelineValue->amountIfComparable(),
                'revenue' => $revenue->amountIfComparable(),
                'cpl' => $cpl->amountIfComparable(),
                'cpo' => $cpo->amountIfComparable(),
                'cpa' => $cpa->amountIfComparable(),
                'roas' => $roas->amountIfComparable(),
                'influenced_pipeline' => $influencedPipeline->amountIfComparable(),
                'influenced_revenue' => $attributionRevenue->amountIfComparable(),
            ],
            'monetary' => collect($monetary)->map->toArray()->all(),
            'currency' => $this->monetarySummary($monetary),
            'attribution_model' => $attributionModel,
            'metric_definitions' => $this->metrics->all()->map->toArray()->all(),
        ];
    }

    public function marketingSpendByPeriod(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $period = 'day'): Collection
    {
        [, $workspaceId, $timezone, $localStart, $localEnd] = $this->reportingContext($workspace, $start, $end);
        $rows = NormalizedDailyPerformance::query()
            ->forWorkspace($workspaceId)
            ->whereDate('date', '>=', $localStart->toDateString())
            ->whereDate('date', '<=', $localEnd->toDateString())
            ->orderBy('date')
            ->get([
                'date',
                'cost',
                'original_cost',
                'original_currency',
                'reporting_cost',
                'reporting_currency',
            ]);

        return $rows
            ->groupBy(fn (NormalizedDailyPerformance $row): string => $this->localPeriodKey($row->date, $timezone, $period))
            ->map(function (Collection $rows, string $periodKey): array {
                $spend = $this->performanceMoneyAggregateFromRows($rows, 'cost');

                return [
                    'period' => $periodKey,
                    'spend' => $spend->amountIfComparable(),
                    'monetary' => $spend->toArray(),
                ];
            })
            ->values();
    }

    public function campaignPerformance(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): Collection
    {
        [, $workspaceId, , $localStart, $localEnd] = $this->reportingContext($workspace, $start, $end);
        $rows = NormalizedDailyPerformance::query()
            ->from('connector_normalized_daily_performances as perf')
            ->leftJoin('connector_normalized_campaigns as campaigns', function ($join): void {
                $join->on('campaigns.workspace_id', '=', 'perf.workspace_id')
                    ->on('campaigns.provider', '=', 'perf.provider')
                    ->on('campaigns.provider_campaign_id', '=', 'perf.entity_id')
                    ->where('perf.entity_type', '=', 'campaign');
            })
            ->where('perf.workspace_id', $workspaceId)
            ->whereDate('perf.date', '>=', $localStart->toDateString())
            ->whereDate('perf.date', '<=', $localEnd->toDateString())
            ->get([
                'perf.provider',
                'perf.entity_id',
                DB::raw('coalesce(campaigns.name, perf.entity_id) as campaign_name'),
                'perf.impressions',
                'perf.clicks',
                'perf.cost',
                'perf.original_cost',
                'perf.original_currency',
                'perf.reporting_cost',
                'perf.reporting_currency',
                'perf.conversions',
                'perf.revenue',
                'perf.original_revenue',
                'perf.reporting_revenue',
            ]);

        return $rows
            ->groupBy(fn (object $row): string => $row->provider.'|'.$row->entity_id.'|'.$row->campaign_name)
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $spend = $this->performanceMoneyAggregateFromRows($rows, 'cost');
                $revenue = $this->performanceMoneyAggregateFromRows($rows, 'revenue');

                return [
                    'provider' => $first->provider,
                    'campaign_id' => $first->entity_id,
                    'campaign_name' => $first->campaign_name,
                    'impressions' => (int) $rows->sum('impressions'),
                    'clicks' => (int) $rows->sum('clicks'),
                    'spend' => $spend->amountIfComparable(),
                    'conversions' => (float) $rows->sum('conversions'),
                    'revenue' => $revenue->amountIfComparable(),
                    'monetary' => [
                        'spend' => $spend->toArray(),
                        'revenue' => $revenue->toArray(),
                    ],
                    'sort_amount' => $this->sortAmount($spend),
                ];
            })
            ->sortByDesc('sort_amount')
            ->map(fn (array $row): array => collect($row)->except('sort_amount')->all())
            ->values();
    }

    public function channelPerformance(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): Collection
    {
        [, $workspaceId, , $localStart, $localEnd] = $this->reportingContext($workspace, $start, $end);
        $rows = NormalizedDailyPerformance::query()
            ->forWorkspace($workspaceId)
            ->whereDate('date', '>=', $localStart->toDateString())
            ->whereDate('date', '<=', $localEnd->toDateString())
            ->get([
                'provider',
                'impressions',
                'clicks',
                'cost',
                'original_cost',
                'original_currency',
                'reporting_cost',
                'reporting_currency',
                'conversions',
                'revenue',
                'original_revenue',
                'reporting_revenue',
            ]);

        return $rows
            ->groupBy('provider')
            ->map(function (Collection $rows, string $provider): array {
                $spend = $this->performanceMoneyAggregateFromRows($rows, 'cost');
                $revenue = $this->performanceMoneyAggregateFromRows($rows, 'revenue');

                return [
                    'channel' => $provider,
                    'impressions' => (int) $rows->sum('impressions'),
                    'clicks' => (int) $rows->sum('clicks'),
                    'spend' => $spend->amountIfComparable(),
                    'conversions' => (float) $rows->sum('conversions'),
                    'revenue' => $revenue->amountIfComparable(),
                    'monetary' => [
                        'spend' => $spend->toArray(),
                        'revenue' => $revenue->toArray(),
                    ],
                    'sort_amount' => $this->sortAmount($spend),
                ];
            })
            ->sortByDesc('sort_amount')
            ->map(fn (array $row): array => collect($row)->except('sort_amount')->all())
            ->values();
    }

    public function sourceMediumPerformance(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): Collection
    {
        [, $workspaceId, , , , $utcStart, $utcEnd] = $this->reportingContext($workspace, $start, $end);
        $rows = AttributionResult::query()
            ->from('attribution_results as results')
            ->join('attribution_touchpoints as touchpoints', 'touchpoints.id', '=', 'results.attribution_touchpoint_id')
            ->join('attribution_conversions as conversions', 'conversions.id', '=', 'results.attribution_conversion_id')
            ->where('results.workspace_id', $workspaceId)
            ->where('results.model_key', $attributionModel)
            ->whereBetween('conversions.occurred_at', [$utcStart, $utcEnd])
            ->get([
                'touchpoints.source',
                'touchpoints.medium',
                'results.credit',
                'results.value as amount',
                DB::raw('coalesce(results.currency, conversions.currency) as currency'),
                'conversions.id as conversion_id',
            ]);

        return $rows
            ->groupBy(fn (object $row): string => ($row->source ?? '').'|'.($row->medium ?? ''))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $revenue = MonetaryAggregate::fromRows($rows);

                return [
                    'source' => $first->source,
                    'medium' => $first->medium,
                    'conversion_credit' => (float) $rows->sum('credit'),
                    'revenue' => $revenue->amountIfComparable(),
                    'conversions' => $rows->pluck('conversion_id')->unique()->count(),
                    'monetary' => [
                        'revenue' => $revenue->toArray(),
                    ],
                ];
            })
            ->values();
    }

    public function pipelineValue(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): ?float
    {
        [, $workspaceId, , , , $utcStart, $utcEnd] = $this->reportingContext($workspace, $start, $end);

        return $this->crmTotals($workspaceId, $utcStart, $utcEnd)['pipeline_value']->amountIfComparable();
    }

    public function wonRevenue(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): ?float
    {
        [, $workspaceId, , , , $utcStart, $utcEnd] = $this->reportingContext($workspace, $start, $end);

        return $this->crmTotals($workspaceId, $utcStart, $utcEnd)['won_revenue']->amountIfComparable();
    }

    public function costPerLead(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): ?float
    {
        $summary = $this->summary($workspace, $start, $end);

        return $summary['metrics']['cpl'];
    }

    public function costPerOpportunity(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): ?float
    {
        $summary = $this->summary($workspace, $start, $end);

        return $summary['metrics']['cpo'];
    }

    public function costPerAcquisition(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): ?float
    {
        $summary = $this->summary($workspace, $start, $end, $attributionModel);

        return $summary['metrics']['cpa'];
    }

    public function roas(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): ?float
    {
        $summary = $this->summary($workspace, $start, $end, $attributionModel);

        return $summary['metrics']['roas'];
    }

    public function influencedPipeline(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): ?float
    {
        [, $workspaceId, , , , $utcStart, $utcEnd] = $this->reportingContext($workspace, $start, $end);

        return $this->attributionTotals($workspaceId, $utcStart, $utcEnd, $attributionModel)['influenced_pipeline']->amountIfComparable();
    }

    public function influencedRevenue(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): ?float
    {
        [, $workspaceId, , , , $utcStart, $utcEnd] = $this->reportingContext($workspace, $start, $end);

        return $this->attributionTotals($workspaceId, $utcStart, $utcEnd, $attributionModel)['revenue']->amountIfComparable();
    }

    public function freshness(Workspace|string $workspace): array
    {
        $workspaceId = $this->workspaceId($workspace);

        return [
            'normalized_performance_at' => NormalizedDailyPerformance::query()->forWorkspace($workspaceId)->max('updated_at'),
            'normalized_crm_at' => NormalizedCrmDeal::query()->forWorkspace($workspaceId)->max('updated_at')
                ?: NormalizedCrmContact::query()->forWorkspace($workspaceId)->max('updated_at'),
            'attribution_at' => AttributionRun::query()->forWorkspace($workspaceId)->where('status', AttributionRun::STATUS_COMPLETED)->max('finished_at'),
        ];
    }

    public function coverage(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): array
    {
        [, $workspaceId, , $localStart, $localEnd, $utcStart, $utcEnd] = $this->reportingContext($workspace, $start, $end);

        return [
            'normalized_performance_rows' => NormalizedDailyPerformance::query()
                ->forWorkspace($workspaceId)
                ->whereDate('date', '>=', $localStart->toDateString())
                ->whereDate('date', '<=', $localEnd->toDateString())
                ->count(),
            'normalized_campaigns' => NormalizedCampaign::query()->forWorkspace($workspaceId)->count(),
            'crm_contacts' => NormalizedCrmContact::query()->forWorkspace($workspaceId)->count(),
            'crm_deals' => NormalizedCrmDeal::query()->forWorkspace($workspaceId)->whereBetween('updated_at', [$utcStart, $utcEnd])->count(),
            'attribution_touchpoints' => AttributionTouchpoint::query()->forWorkspace($workspaceId)->whereBetween('occurred_at', [$utcStart, $utcEnd])->count(),
            'attribution_conversions' => AttributionConversion::query()->forWorkspace($workspaceId)->whereBetween('occurred_at', [$utcStart, $utcEnd])->count(),
        ];
    }

    public function reportingTimezone(Workspace|string $workspace): string
    {
        $workspaceModel = $workspace instanceof Workspace
            ? $workspace
            : Workspace::query()->find((string) $workspace);

        return $workspaceModel?->reportingTimezone() ?? Workspace::defaultReportingTimezone();
    }

    private function marketingTotals(string $workspaceId, Carbon $localStart, Carbon $localEnd): array
    {
        $row = NormalizedDailyPerformance::query()
            ->selectRaw('coalesce(sum(impressions), 0) as impressions, coalesce(sum(clicks), 0) as clicks, coalesce(sum(conversions), 0) as conversions')
            ->forWorkspace($workspaceId)
            ->whereDate('date', '>=', $localStart->toDateString())
            ->whereDate('date', '<=', $localEnd->toDateString())
            ->first();

        return [
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'spend' => $this->performanceMoneyAggregate($workspaceId, $localStart, $localEnd, 'cost'),
            'conversions' => (float) ($row->conversions ?? 0),
            'revenue' => $this->performanceMoneyAggregate($workspaceId, $localStart, $localEnd, 'revenue'),
        ];
    }

    private function crmTotals(string $workspaceId, Carbon $utcStart, Carbon $utcEnd): array
    {
        $leads = NormalizedCrmContact::query()
            ->forWorkspace($workspaceId)
            ->whereBetween('created_at', [$utcStart, $utcEnd])
            ->count();

        $opportunities = NormalizedCrmDeal::query()
            ->forWorkspace($workspaceId)
            ->whereBetween('updated_at', [$utcStart, $utcEnd])
            ->count();

        return [
            'leads' => $leads,
            'opportunities' => $opportunities,
            'pipeline_value' => $this->crmDealAggregate($workspaceId, $utcStart, $utcEnd, false),
            'won_revenue' => $this->crmDealAggregate($workspaceId, $utcStart, $utcEnd, true),
        ];
    }

    private function attributionTotals(string $workspaceId, Carbon $utcStart, Carbon $utcEnd, string $modelKey): array
    {
        $row = AttributionResult::query()
            ->from('attribution_results as results')
            ->join('attribution_conversions as conversions', 'conversions.id', '=', 'results.attribution_conversion_id')
            ->where('results.workspace_id', $workspaceId)
            ->where('results.model_key', $modelKey)
            ->whereBetween('conversions.occurred_at', [$utcStart, $utcEnd])
            ->where('results.match_confidence', '!=', 'unmatched')
            ->selectRaw('count(distinct conversions.id) as conversions')
            ->first();

        return [
            'conversions' => (int) ($row->conversions ?? 0),
            'revenue' => $this->attributionResultAggregate($workspaceId, $utcStart, $utcEnd, $modelKey),
            'influenced_pipeline' => $this->attributionResultAggregate($workspaceId, $utcStart, $utcEnd, $modelKey, 'opportunity'),
        ];
    }

    private function performanceMoneyAggregate(string $workspaceId, Carbon $localStart, Carbon $localEnd, string $metric): MonetaryAggregate
    {
        $rows = NormalizedDailyPerformance::query()
            ->forWorkspace($workspaceId)
            ->whereDate('date', '>=', $localStart->toDateString())
            ->whereDate('date', '<=', $localEnd->toDateString())
            ->get($this->performanceMoneyColumns($metric));

        return $this->performanceMoneyAggregateFromRows($rows, $metric);
    }

    private function performanceMoneyAggregateFromRows(Collection $rows, string $metric): MonetaryAggregate
    {
        $amountColumn = $metric === 'revenue' ? 'revenue' : 'cost';
        $originalColumn = $metric === 'revenue' ? 'original_revenue' : 'original_cost';
        $reportingColumn = $metric === 'revenue' ? 'reporting_revenue' : 'reporting_cost';

        return MonetaryAggregate::fromRows(
            $rows->map(fn (object $row): array => [
                'amount' => $this->firstNumeric($row->{$originalColumn} ?? null, $row->{$amountColumn} ?? null),
                'currency' => $row->original_currency ?? null,
                'reporting_amount' => $this->firstNumeric($row->{$reportingColumn} ?? null),
                'reporting_currency' => $row->reporting_currency ?? null,
            ])->all(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function performanceMoneyColumns(string $metric): array
    {
        $columns = [
            'original_currency',
            'reporting_currency',
        ];

        if ($metric === 'revenue') {
            return array_merge($columns, ['revenue', 'original_revenue', 'reporting_revenue']);
        }

        return array_merge($columns, ['cost', 'original_cost', 'reporting_cost']);
    }

    private function crmDealAggregate(string $workspaceId, Carbon $utcStart, Carbon $utcEnd, bool $wonOnly): MonetaryAggregate
    {
        $query = NormalizedCrmDeal::query()
            ->forWorkspace($workspaceId)
            ->whereBetween('updated_at', [$utcStart, $utcEnd]);

        if ($wonOnly) {
            $query->whereIn('status', ['won', 'closed_won', 'true']);
        } else {
            $query->whereNotIn('status', ['won', 'closed_won', 'lost', 'closed_lost']);
        }

        return MonetaryAggregate::fromRows($query->get([
            'amount',
            'currency',
        ]));
    }

    private function attributionResultAggregate(
        string $workspaceId,
        Carbon $utcStart,
        Carbon $utcEnd,
        string $modelKey,
        ?string $conversionType = null,
    ): MonetaryAggregate {
        $query = AttributionResult::query()
            ->from('attribution_results as results')
            ->join('attribution_conversions as conversions', 'conversions.id', '=', 'results.attribution_conversion_id')
            ->where('results.workspace_id', $workspaceId)
            ->where('results.model_key', $modelKey)
            ->whereBetween('conversions.occurred_at', [$utcStart, $utcEnd])
            ->where('results.match_confidence', '!=', 'unmatched');

        if ($conversionType !== null) {
            $query->where('conversions.conversion_type', $conversionType);
        }

        return MonetaryAggregate::fromRows($query->get([
            'results.value as amount',
            DB::raw('coalesce(results.currency, conversions.currency) as currency'),
        ]));
    }

    private function preferredRevenue(MonetaryAggregate $crmRevenue, MonetaryAggregate $attributionRevenue): MonetaryAggregate
    {
        if ($crmRevenue->comparable && ! $attributionRevenue->comparable) {
            return $crmRevenue;
        }

        if ($attributionRevenue->comparable && ! $crmRevenue->comparable) {
            return $attributionRevenue;
        }

        if (! $crmRevenue->comparable || ! $attributionRevenue->comparable) {
            return MonetaryAggregate::unavailable(array_values(array_unique(array_merge(
                $crmRevenue->warnings,
                $attributionRevenue->warnings,
                ['Revenue currency is not comparable.'],
            ))));
        }

        if ($crmRevenue->currency !== $attributionRevenue->currency) {
            return MonetaryAggregate::fromRows([
                ['amount' => $crmRevenue->amount, 'currency' => $crmRevenue->currency],
                ['amount' => $attributionRevenue->amount, 'currency' => $attributionRevenue->currency],
            ]);
        }

        $amount = max((float) $crmRevenue->amount, (float) $attributionRevenue->amount);

        return new MonetaryAggregate(
            $crmRevenue->status === MonetaryAggregate::STATUS_CONVERTED || $attributionRevenue->status === MonetaryAggregate::STATUS_CONVERTED
                ? MonetaryAggregate::STATUS_CONVERTED
                : MonetaryAggregate::STATUS_SINGLE_CURRENCY,
            $crmRevenue->currency,
            $amount,
            [$crmRevenue->currency => $amount],
            true,
            $crmRevenue->conversionCoverage,
            [],
        );
    }

    /**
     * @param  array<string, MonetaryAggregate>  $aggregates
     * @return array<string, mixed>
     */
    private function monetarySummary(array $aggregates): array
    {
        $statuses = collect($aggregates)->pluck('status');
        $status = match (true) {
            $statuses->contains(MonetaryAggregate::STATUS_MIXED_CURRENCY) => MonetaryAggregate::STATUS_MIXED_CURRENCY,
            $statuses->contains(MonetaryAggregate::STATUS_UNAVAILABLE) => MonetaryAggregate::STATUS_UNAVAILABLE,
            $statuses->contains(MonetaryAggregate::STATUS_CONVERTED) => MonetaryAggregate::STATUS_CONVERTED,
            default => MonetaryAggregate::STATUS_SINGLE_CURRENCY,
        };
        $coverage = collect($aggregates)->reduce(function (array $carry, MonetaryAggregate $aggregate): array {
            $total = (int) ($aggregate->conversionCoverage['total_rows'] ?? 0);
            $converted = (int) ($aggregate->conversionCoverage['converted_rows'] ?? 0);

            $carry['total_rows'] += $total;
            $carry['converted_rows'] += $converted;

            return $carry;
        }, ['total_rows' => 0, 'converted_rows' => 0]);

        $coverage['missing_rows'] = max(0, $coverage['total_rows'] - $coverage['converted_rows']);
        $coverage['ratio'] = $coverage['total_rows'] > 0
            ? round($coverage['converted_rows'] / $coverage['total_rows'], 4)
            : 0.0;

        return [
            'status' => $status,
            'comparable' => ! in_array($status, [MonetaryAggregate::STATUS_MIXED_CURRENCY, MonetaryAggregate::STATUS_UNAVAILABLE], true),
            'currencies_represented' => collect($aggregates)
                ->flatMap(fn (MonetaryAggregate $aggregate): array => $aggregate->currenciesRepresented())
                ->unique()
                ->values()
                ->all(),
            'conversion_coverage' => $coverage,
            'warnings' => collect($aggregates)
                ->flatMap(fn (MonetaryAggregate $aggregate): array => $aggregate->warnings)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{0: Workspace|null, 1: string, 2: string, 3: Carbon, 4: Carbon, 5: Carbon, 6: Carbon}
     */
    private function reportingContext(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): array
    {
        $workspaceModel = $workspace instanceof Workspace
            ? $workspace
            : Workspace::query()->find((string) $workspace);
        $workspaceId = $workspaceModel instanceof Workspace ? (string) $workspaceModel->id : (string) $workspace;
        $timezone = $workspaceModel?->reportingTimezone() ?? Workspace::defaultReportingTimezone();
        [$localStart, $localEnd, $utcStart, $utcEnd] = $this->window($start, $end, $timezone);

        return [$workspaceModel, $workspaceId, $timezone, $localStart, $localEnd, $utcStart, $utcEnd];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon}
     */
    private function window(Carbon|string $start, Carbon|string $end, string $timezone): array
    {
        $localStart = $this->asLocalCarbon($start, $timezone)->startOfDay();
        $localEnd = $this->asLocalCarbon($end, $timezone)->endOfDay();

        return [
            $localStart,
            $localEnd,
            $localStart->copy()->utc(),
            $localEnd->copy()->utc(),
        ];
    }

    private function asLocalCarbon(Carbon|string $value, string $timezone): Carbon
    {
        return $value instanceof Carbon
            ? $value->copy()->timezone($timezone)
            : Carbon::parse($value, $timezone);
    }

    private function periodPayload(Carbon $localStart, Carbon $localEnd, Carbon $utcStart, Carbon $utcEnd, string $timezone): array
    {
        return [
            'start' => $localStart->toDateTimeString(),
            'end' => $localEnd->toDateTimeString(),
            'timezone' => $timezone,
            'utc_start' => $utcStart->toDateTimeString(),
            'utc_end' => $utcEnd->toDateTimeString(),
        ];
    }

    private function workspaceId(Workspace|string $workspace): string
    {
        return $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;
    }

    private function divide(float $numerator, float|int $denominator): ?float
    {
        return $denominator == 0 ? null : round($numerator / (float) $denominator, 6);
    }

    private function firstNumeric(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function sortAmount(MonetaryAggregate $aggregate): float
    {
        return $aggregate->amount ?? array_sum($aggregate->totalsByCurrency);
    }

    private function localPeriodKey(Carbon|string $date, string $timezone, string $period): string
    {
        $local = $date instanceof Carbon
            ? $date->copy()->timezone($timezone)
            : Carbon::parse($date, $timezone);

        return $period === 'month'
            ? $local->startOfMonth()->toDateString()
            : $local->toDateString();
    }
}
