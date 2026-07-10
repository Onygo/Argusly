<?php

namespace App\Services\Reporting;

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
        $workspaceId = $this->workspaceId($workspace);
        [$start, $end] = $this->window($start, $end);
        $marketing = $this->marketingTotals($workspaceId, $start, $end);
        $crm = $this->crmTotals($workspaceId, $start, $end);
        $attribution = $this->attributionTotals($workspaceId, $start, $end, $attributionModel);

        $spend = (float) $marketing['spend'];
        $leads = (int) $crm['leads'];
        $opportunities = (int) $crm['opportunities'];
        $conversions = (int) $attribution['conversions'];
        $revenue = (float) max((float) $crm['won_revenue'], (float) $attribution['revenue']);

        return [
            'period' => $this->periodPayload($start, $end),
            'metrics' => [
                'impressions' => (int) $marketing['impressions'],
                'clicks' => (int) $marketing['clicks'],
                'ctr' => $this->divide((float) $marketing['clicks'], (float) $marketing['impressions']),
                'cpc' => $this->divide($spend, (float) $marketing['clicks']),
                'cpm' => $this->divide($spend * 1000, (float) $marketing['impressions']),
                'spend' => $spend,
                'leads' => $leads,
                'opportunities' => $opportunities,
                'conversions' => $conversions,
                'pipeline_value' => (float) $crm['pipeline_value'],
                'revenue' => $revenue,
                'cpl' => $this->divide($spend, $leads),
                'cpo' => $this->divide($spend, $opportunities),
                'cpa' => $this->divide($spend, $conversions),
                'roas' => $this->divide($revenue, $spend),
                'influenced_pipeline' => (float) $attribution['influenced_pipeline'],
                'influenced_revenue' => (float) $attribution['revenue'],
            ],
            'attribution_model' => $attributionModel,
            'metric_definitions' => $this->metrics->all()->map->toArray()->all(),
        ];
    }

    public function marketingSpendByPeriod(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $period = 'day'): Collection
    {
        $workspaceId = $this->workspaceId($workspace);
        [$start, $end] = $this->window($start, $end);
        $dateExpression = $period === 'month' ? $this->monthExpression('date') : 'date';

        return NormalizedDailyPerformance::query()
            ->selectRaw("{$dateExpression} as period, sum(cost) as spend")
            ->forWorkspace($workspaceId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    public function campaignPerformance(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): Collection
    {
        $workspaceId = $this->workspaceId($workspace);
        [$start, $end] = $this->window($start, $end);

        return NormalizedDailyPerformance::query()
            ->from('connector_normalized_daily_performances as perf')
            ->leftJoin('connector_normalized_campaigns as campaigns', function ($join): void {
                $join->on('campaigns.workspace_id', '=', 'perf.workspace_id')
                    ->on('campaigns.provider', '=', 'perf.provider')
                    ->on('campaigns.provider_campaign_id', '=', 'perf.entity_id')
                    ->where('perf.entity_type', '=', 'campaign');
            })
            ->where('perf.workspace_id', $workspaceId)
            ->whereBetween('perf.date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('perf.provider', 'perf.entity_id', 'campaigns.name')
            ->orderByDesc(DB::raw('sum(perf.cost)'))
            ->get([
                'perf.provider',
                'perf.entity_id as campaign_id',
                DB::raw('coalesce(campaigns.name, perf.entity_id) as campaign_name'),
                DB::raw('sum(perf.impressions) as impressions'),
                DB::raw('sum(perf.clicks) as clicks'),
                DB::raw('sum(perf.cost) as spend'),
                DB::raw('sum(perf.conversions) as conversions'),
                DB::raw('sum(perf.revenue) as revenue'),
            ]);
    }

    public function channelPerformance(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): Collection
    {
        $workspaceId = $this->workspaceId($workspace);
        [$start, $end] = $this->window($start, $end);

        return NormalizedDailyPerformance::query()
            ->selectRaw('provider as channel, sum(impressions) as impressions, sum(clicks) as clicks, sum(cost) as spend, sum(conversions) as conversions, sum(revenue) as revenue')
            ->forWorkspace($workspaceId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('provider')
            ->orderByDesc(DB::raw('sum(cost)'))
            ->get();
    }

    public function sourceMediumPerformance(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): Collection
    {
        $workspaceId = $this->workspaceId($workspace);
        [$start, $end] = $this->window($start, $end);

        return AttributionResult::query()
            ->from('attribution_results as results')
            ->join('attribution_touchpoints as touchpoints', 'touchpoints.id', '=', 'results.attribution_touchpoint_id')
            ->join('attribution_conversions as conversions', 'conversions.id', '=', 'results.attribution_conversion_id')
            ->where('results.workspace_id', $workspaceId)
            ->where('results.model_key', $attributionModel)
            ->whereBetween('conversions.occurred_at', [$start, $end])
            ->groupBy('touchpoints.source', 'touchpoints.medium')
            ->get([
                'touchpoints.source',
                'touchpoints.medium',
                DB::raw('sum(results.credit) as conversion_credit'),
                DB::raw('sum(results.value) as revenue'),
                DB::raw('count(distinct conversions.id) as conversions'),
            ]);
    }

    public function pipelineValue(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): float
    {
        [$start, $end] = $this->window($start, $end);

        return (float) $this->crmTotals($this->workspaceId($workspace), $start, $end)['pipeline_value'];
    }

    public function wonRevenue(Workspace|string $workspace, Carbon|string $start, Carbon|string $end): float
    {
        [$start, $end] = $this->window($start, $end);

        return (float) $this->crmTotals($this->workspaceId($workspace), $start, $end)['won_revenue'];
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

    public function influencedPipeline(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): float
    {
        [$start, $end] = $this->window($start, $end);

        return (float) $this->attributionTotals($this->workspaceId($workspace), $start, $end, $attributionModel)['influenced_pipeline'];
    }

    public function influencedRevenue(Workspace|string $workspace, Carbon|string $start, Carbon|string $end, string $attributionModel = 'last_touch'): float
    {
        [$start, $end] = $this->window($start, $end);

        return (float) $this->attributionTotals($this->workspaceId($workspace), $start, $end, $attributionModel)['revenue'];
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
        $workspaceId = $this->workspaceId($workspace);
        [$start, $end] = $this->window($start, $end);

        return [
            'normalized_performance_rows' => NormalizedDailyPerformance::query()->forWorkspace($workspaceId)->whereBetween('date', [$start->toDateString(), $end->toDateString()])->count(),
            'normalized_campaigns' => NormalizedCampaign::query()->forWorkspace($workspaceId)->count(),
            'crm_contacts' => NormalizedCrmContact::query()->forWorkspace($workspaceId)->count(),
            'crm_deals' => NormalizedCrmDeal::query()->forWorkspace($workspaceId)->whereBetween('updated_at', [$start, $end])->count(),
            'attribution_touchpoints' => AttributionTouchpoint::query()->forWorkspace($workspaceId)->whereBetween('occurred_at', [$start, $end])->count(),
            'attribution_conversions' => AttributionConversion::query()->forWorkspace($workspaceId)->whereBetween('occurred_at', [$start, $end])->count(),
        ];
    }

    private function marketingTotals(string $workspaceId, Carbon $start, Carbon $end): array
    {
        $row = NormalizedDailyPerformance::query()
            ->selectRaw('coalesce(sum(impressions), 0) as impressions, coalesce(sum(clicks), 0) as clicks, coalesce(sum(cost), 0) as spend, coalesce(sum(conversions), 0) as conversions, coalesce(sum(revenue), 0) as revenue')
            ->forWorkspace($workspaceId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->first();

        return [
            'impressions' => (int) ($row->impressions ?? 0),
            'clicks' => (int) ($row->clicks ?? 0),
            'spend' => (float) ($row->spend ?? 0),
            'conversions' => (float) ($row->conversions ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
        ];
    }

    private function crmTotals(string $workspaceId, Carbon $start, Carbon $end): array
    {
        $leads = NormalizedCrmContact::query()
            ->forWorkspace($workspaceId)
            ->whereBetween('created_at', [$start, $end])
            ->count();

        $opportunities = NormalizedCrmDeal::query()
            ->forWorkspace($workspaceId)
            ->whereBetween('updated_at', [$start, $end])
            ->count();

        $pipeline = NormalizedCrmDeal::query()
            ->forWorkspace($workspaceId)
            ->whereBetween('updated_at', [$start, $end])
            ->whereNotIn('status', ['won', 'closed_won', 'lost', 'closed_lost'])
            ->sum('amount');

        $wonRevenue = NormalizedCrmDeal::query()
            ->forWorkspace($workspaceId)
            ->whereBetween('updated_at', [$start, $end])
            ->whereIn('status', ['won', 'closed_won', 'true'])
            ->sum('amount');

        return [
            'leads' => $leads,
            'opportunities' => $opportunities,
            'pipeline_value' => (float) $pipeline,
            'won_revenue' => (float) $wonRevenue,
        ];
    }

    private function attributionTotals(string $workspaceId, Carbon $start, Carbon $end, string $modelKey): array
    {
        $row = AttributionResult::query()
            ->from('attribution_results as results')
            ->join('attribution_conversions as conversions', 'conversions.id', '=', 'results.attribution_conversion_id')
            ->where('results.workspace_id', $workspaceId)
            ->where('results.model_key', $modelKey)
            ->whereBetween('conversions.occurred_at', [$start, $end])
            ->where('results.match_confidence', '!=', 'unmatched')
            ->selectRaw('count(distinct conversions.id) as conversions, coalesce(sum(results.value), 0) as revenue')
            ->first();

        $pipeline = AttributionResult::query()
            ->from('attribution_results as results')
            ->join('attribution_conversions as conversions', 'conversions.id', '=', 'results.attribution_conversion_id')
            ->where('results.workspace_id', $workspaceId)
            ->where('results.model_key', $modelKey)
            ->where('conversions.conversion_type', 'opportunity')
            ->whereBetween('conversions.occurred_at', [$start, $end])
            ->where('results.match_confidence', '!=', 'unmatched')
            ->sum('results.value');

        return [
            'conversions' => (int) ($row->conversions ?? 0),
            'revenue' => (float) ($row->revenue ?? 0),
            'influenced_pipeline' => (float) $pipeline,
        ];
    }

    private function workspaceId(Workspace|string $workspace): string
    {
        return $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(Carbon|string $start, Carbon|string $end): array
    {
        return [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()];
    }

    private function periodPayload(Carbon $start, Carbon $end): array
    {
        return [
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
        ];
    }

    private function divide(float $numerator, float|int $denominator): ?float
    {
        return $denominator == 0 ? null : round($numerator / (float) $denominator, 6);
    }

    private function monthExpression(string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return $driver === 'sqlite'
            ? "strftime('%Y-%m-01', {$column})"
            : "date_format({$column}, '%Y-%m-01')";
    }
}
