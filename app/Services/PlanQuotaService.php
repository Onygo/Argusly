<?php

namespace App\Services;

use App\Models\ClientSite;
use App\Models\CrossLinkPermission;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PlanQuotaService
{
    public const METRIC_ARTICLES_GENERATED = 'articles_generated';
    public const METRIC_LLM_QUERIES_RUN = 'llm_queries_run';
    public const METRIC_AUDIT_PAGES_CRAWLED = 'audit_pages_crawled';

    /** @var list<string> */
    private const METRICS = [
        self::METRIC_ARTICLES_GENERATED,
        self::METRIC_LLM_QUERIES_RUN,
        self::METRIC_AUDIT_PAGES_CRAWLED,
    ];

    /** @var array<string, string> */
    private const ENFORCED_METRIC_TO_FEATURE = [
        self::METRIC_LLM_QUERIES_RUN => 'llm_tracking_queries_per_month_limit',
        self::METRIC_AUDIT_PAGES_CRAWLED => 'seo_audit_crawl_pages_per_month_limit',
    ];

    public function __construct(private readonly FeatureGate $featureGate)
    {
    }

    public function canGenerateArticle(Workspace $workspace, ?ClientSite $site = null): bool
    {
        return true;
    }

    public function assertCanGenerateArticle(Workspace $workspace, ?ClientSite $site = null): void
    {
        // Article generation is credit-billed, so usage remains report-only and must not block execution.
    }

    public function canRunLlmQuery(Workspace $workspace, ?ClientSite $site = null): bool
    {
        return $this->canUseMetric($workspace, $site, self::METRIC_LLM_QUERIES_RUN, 1);
    }

    public function assertCanRunLlmQuery(Workspace $workspace, ?ClientSite $site = null): void
    {
        $this->assertMetricAllowance($workspace, $site, self::METRIC_LLM_QUERIES_RUN, 1);
    }

    public function canCrawlAuditPages(Workspace $workspace, ?ClientSite $site, int $pagesRequested): bool
    {
        return $this->canUseMetric($workspace, $site, self::METRIC_AUDIT_PAGES_CRAWLED, max(1, $pagesRequested));
    }

    public function assertCanCrawlAuditPages(Workspace $workspace, ?ClientSite $site, int $pagesRequested): void
    {
        $this->assertMetricAllowance($workspace, $site, self::METRIC_AUDIT_PAGES_CRAWLED, max(1, $pagesRequested));
    }

    public function canAddCompetitor(Workspace $workspace, ?ClientSite $site = null): bool
    {
        $limit = $this->limitForFeature($workspace, 'competitor_slots_limit', -1);
        if ($limit < 0) {
            return true;
        }

        if ($site) {
            $used = (int) SiteCompetitor::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_site_id', $site->id)
                ->where('is_active', true)
                ->count();
        } else {
            $used = (int) CrossLinkPermission::query()
                ->where('from_workspace_id', $workspace->id)
                ->whereIn('status', ['pending', 'approved'])
                ->count();
        }

        return $used < $limit;
    }

    public function assertCanAddCompetitor(Workspace $workspace, ?ClientSite $site = null): void
    {
        if ($this->canAddCompetitor($workspace, $site)) {
            return;
        }

        $limit = $this->limitForFeature($workspace, 'competitor_slots_limit', -1);

        if ($site) {
            throw new RuntimeException(sprintf(
                'Competitor slots limit reached (%d) for this site.',
                $limit
            ));
        }

        throw new RuntimeException(sprintf(
            'Competitor slots limit reached (%d) for this workspace.',
            $limit
        ));
    }

    public function incrementUsage(
        Workspace $workspace,
        ?ClientSite $site,
        string $metric,
        int $amount = 1,
        ?string $period = null
    ): WorkspaceUsage {
        if (! in_array($metric, self::METRICS, true)) {
            throw new RuntimeException(sprintf('Unknown quota metric "%s".', $metric));
        }

        $amount = max(1, $amount);
        $periodYm = $this->normalizePeriodYm($period);
        $legacyYearMonth = substr($periodYm, 0, 4) . '-' . substr($periodYm, 4, 2);
        $siteId = $site?->id;

        return DB::transaction(function () use (
            $workspace,
            $siteId,
            $periodYm,
            $legacyYearMonth,
            $metric,
            $amount
        ): WorkspaceUsage {
            $query = WorkspaceUsage::query()
                ->where('workspace_id', $workspace->id)
                ->where('period_ym', $periodYm);

            if ($siteId) {
                $query->where('site_id', $siteId);
            } else {
                $query->whereNull('site_id');
            }

            $row = $query->lockForUpdate()->first();

            if (! $row) {
                $row = WorkspaceUsage::query()->create([
                    'workspace_id' => $workspace->id,
                    'site_id' => $siteId,
                    'year_month' => $legacyYearMonth,
                    'period_ym' => $periodYm,
                    'briefs_count' => 0,
                    'drafts_count' => 0,
                    self::METRIC_ARTICLES_GENERATED => 0,
                    self::METRIC_LLM_QUERIES_RUN => 0,
                    self::METRIC_AUDIT_PAGES_CRAWLED => 0,
                ]);
            }

            $row->{$metric} = (int) $row->{$metric} + $amount;
            $row->save();

            return $row;
        });
    }

    public function periodUsage(Workspace $workspace, string $metric, ?string $period = null): int
    {
        if (! in_array($metric, self::METRICS, true)) {
            throw new RuntimeException(sprintf('Unknown quota metric "%s".', $metric));
        }

        $periodYm = $this->normalizePeriodYm($period);

        return (int) WorkspaceUsage::query()
            ->where('workspace_id', $workspace->id)
            ->where('period_ym', $periodYm)
            ->sum($metric);
    }

    public function limitForMetric(Workspace $workspace, string $metric, int $default = -1): int
    {
        if (! in_array($metric, self::METRICS, true)) {
            throw new RuntimeException(sprintf('Unknown quota metric "%s".', $metric));
        }

        if (! isset(self::ENFORCED_METRIC_TO_FEATURE[$metric])) {
            return -1;
        }

        return $this->limitForFeature($workspace, self::ENFORCED_METRIC_TO_FEATURE[$metric], $default);
    }

    public function limitForFeature(Workspace $workspace, string $featureKey, int $default = -1): int
    {
        $value = $this->featureGate->value($workspace, $featureKey, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function canUseMetric(Workspace $workspace, ?ClientSite $site, string $metric, int $requested): bool
    {
        if (! isset(self::ENFORCED_METRIC_TO_FEATURE[$metric])) {
            return true;
        }

        $limit = $this->limitForMetric($workspace, $metric, -1);
        if ($limit < 0) {
            return true;
        }

        $periodYm = now()->format('Ym');
        $query = WorkspaceUsage::query()
            ->where('workspace_id', $workspace->id)
            ->where('period_ym', $periodYm);

        $used = (int) $query->sum($metric);

        return ($used + $requested) <= $limit;
    }

    private function assertMetricAllowance(Workspace $workspace, ?ClientSite $site, string $metric, int $requested): void
    {
        if ($this->canUseMetric($workspace, $site, $metric, $requested)) {
            return;
        }

        $limit = $this->limitForMetric($workspace, $metric, -1);

        throw new RuntimeException(sprintf(
            'Monthly quota exceeded for %s (%d).',
            $metric,
            $limit
        ));
    }

    private function normalizePeriodYm(?string $period): string
    {
        $candidate = trim((string) $period);
        if ($candidate === '') {
            return now()->format('Ym');
        }

        if (preg_match('/^\d{6}$/', $candidate) === 1) {
            return $candidate;
        }

        if (preg_match('/^\d{4}-\d{2}$/', $candidate) === 1) {
            return str_replace('-', '', $candidate);
        }

        throw new RuntimeException(sprintf('Invalid period format "%s". Use YYYYMM.', $candidate));
    }
}
