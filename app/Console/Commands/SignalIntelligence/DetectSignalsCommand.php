<?php

namespace App\Console\Commands\SignalIntelligence;

use App\Models\ClientSite;
use App\Models\SignalDetection;
use App\Models\Workspace;
use App\Services\SignalIntelligence\BrandMonitoringDetectionService;
use App\Services\SignalIntelligence\CompetitorMonitoringDetectionService;
use App\Services\SignalIntelligence\RiskDetectionService;
use App\Services\SignalIntelligence\SignalProcessingRunService;
use App\Services\SignalIntelligence\TrendDetectionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class DetectSignalsCommand extends Command
{
    protected $signature = 'signal-intelligence:detect
        {--workspace= : Optional workspace id}
        {--site= : Optional client site id}
        {--category=all : brand_monitoring, competitor_monitoring, trend_detection, risk_detection, or all}
        {--from= : Optional period start}
        {--to= : Optional period end}
        {--limit=100 : Max events per category query}';

    protected $description = 'Convert Signal Intelligence events into scored detections.';

    public function handle(
        BrandMonitoringDetectionService $brand,
        CompetitorMonitoringDetectionService $competitors,
        TrendDetectionService $trends,
        RiskDetectionService $risks,
        SignalProcessingRunService $runs,
    ): int {
        if (! (bool) config('features.signal_intelligence', false)) {
            $this->warn('Signal Intelligence is disabled. No detections processed.');

            return self::SUCCESS;
        }

        $category = (string) $this->option('category');
        $supported = ['brand_monitoring', 'competitor_monitoring', 'trend_detection', 'risk_detection', 'all'];

        if (! in_array($category, $supported, true)) {
            $this->error('Unsupported category. Use: '.implode(', ', $supported));

            return self::FAILURE;
        }

        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from')) : CarbonImmutable::now()->subDays(7);
        $to = $this->option('to') ? CarbonImmutable::parse((string) $this->option('to')) : CarbonImmutable::now();
        $limit = max(1, (int) $this->option('limit'));
        $site = $this->site();
        $total = 0;

        foreach ($this->workspaces($site) as $workspace) {
            $run = $runs->startRun($workspace, 'signal_detection', clientSite: $site, input: [
                'category' => $category,
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'limit' => $limit,
            ]);

            try {
                $detections = collect();

                if ($category === 'all' || $category === SignalDetection::CATEGORY_BRAND_MONITORING) {
                    $detections = $detections->merge($brand->detect($workspace, $site, $from, $to, $limit));
                }

                if ($category === 'all' || $category === SignalDetection::CATEGORY_COMPETITOR_MONITORING) {
                    $detections = $detections->merge($competitors->detect($workspace, $site, $from, $to, $limit));
                }

                if ($category === 'all' || $category === SignalDetection::CATEGORY_TREND_DETECTION) {
                    $detections = $detections->merge($trends->detect($workspace, $site, $from, $to, $limit));
                }

                if ($category === 'all' || $category === SignalDetection::CATEGORY_RISK_DETECTION) {
                    $detections = $detections->merge($risks->detect($workspace, $site, $from, $to, $limit));
                }

                $total += $detections->count();
                $runs->markSucceeded($run, [
                    'detections_created' => $detections->count(),
                    'items_seen' => $detections->count(),
                    'category' => $category,
                ]);

                $this->line(sprintf(
                    'Workspace %s: %d detection(s).',
                    $workspace->display_name,
                    $detections->count()
                ));
            } catch (\Throwable $exception) {
                $runs->markFailed($run, $exception->getMessage());
                $this->error(sprintf('Workspace %s failed: %s', $workspace->display_name, $exception->getMessage()));

                return self::FAILURE;
            }
        }

        $this->info("Signal detection completed. Detections touched: {$total}.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int,Workspace>
     */
    private function workspaces(?ClientSite $site): Collection
    {
        $workspaceId = trim((string) $this->option('workspace'));

        return Workspace::query()
            ->when($workspaceId !== '', fn ($query) => $query->whereKey($workspaceId))
            ->when($site, fn ($query) => $query->whereKey($site->workspace_id))
            ->orderBy('created_at')
            ->get();
    }

    private function site(): ?ClientSite
    {
        $siteId = trim((string) $this->option('site'));

        if ($siteId === '') {
            return null;
        }

        return ClientSite::query()->findOrFail($siteId);
    }
}
