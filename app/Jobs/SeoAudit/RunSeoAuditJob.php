<?php

namespace App\Jobs\SeoAudit;

use App\Models\ClientSite;
use App\Models\SeoAudit;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditPage;
use App\Services\Integrations\ApiWebhookPublisher;
use App\Services\Integrations\AsyncOperationService;
use App\Services\PlanQuotaService;
use App\Services\SeoAudit\SeoAuditCrawlerService;
use Illuminate\Support\Collection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSeoAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $backoff = 30;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $siteId,
        public readonly int $maxPages = 50,
        public readonly ?string $operationId = null,
        public readonly ?string $contentDestinationId = null,
    ) {}

    public function handle(
        SeoAuditCrawlerService $crawler,
        PlanQuotaService $quotaService,
        AsyncOperationService $operationService,
        ApiWebhookPublisher $webhookPublisher,
    ): void {
        $site = ClientSite::query()->with('workspace')->find($this->siteId);
        if (! $site || ! $site->workspace) {
            return;
        }

        if ($this->operationId) {
            $operationService->markProcessing($this->operationId);
        }

        $requested = max(1, $this->maxPages);
        $limit = $quotaService->limitForMetric($site->workspace, PlanQuotaService::METRIC_AUDIT_PAGES_CRAWLED, -1);
        $used = $quotaService->periodUsage($site->workspace, PlanQuotaService::METRIC_AUDIT_PAGES_CRAWLED, now()->format('Ym'));
        $remaining = $limit < 0 ? $requested : max(0, $limit - $used);
        $allowed = min($requested, $remaining);

        $audit = SeoAudit::query()->create([
            'workspace_id' => $site->workspace_id,
            'client_site_id' => $site->id,
            'content_destination_id' => $this->contentDestinationId,
            'started_at' => now(),
            'status' => 'running',
            'meta' => [
                'requested_pages' => $requested,
                'limit' => $limit,
                'used_before' => $used,
                'allowed_pages' => $allowed,
                'operation_id' => $this->operationId,
            ],
        ]);

        if ($allowed <= 0) {
            $audit->update([
                'finished_at' => now(),
                'status' => 'failed',
                'error_message' => 'Monthly SEO audit page cap reached.',
            ]);
            if ($this->operationId) {
                $operationService->markFailed(
                    operationId: $this->operationId,
                    errorMessage: 'Monthly SEO audit page cap reached.',
                    errorCode: 'SEO_AUDIT_CAP_REACHED',
                    resultPayload: ['seo_audit_id' => $audit->id],
                );
            }
            $webhookPublisher->publish(
                workspace: $site->workspace,
                eventType: 'seo_audit.failed',
                payload: [
                    'seo_audit_id' => $audit->id,
                    'operation_id' => $this->operationId,
                    'error' => 'Monthly SEO audit page cap reached.',
                ],
                contentDestinationId: $this->contentDestinationId,
                eventId: $this->operationId ?: (string) $audit->id,
            );

            return;
        }

        try {
            $result = $crawler->crawl($site, $allowed);
            $pages = (array) ($result['pages'] ?? []);
            $issues = (array) ($result['issues'] ?? []);
            $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : null;

            $pageMap = [];
            foreach ($pages as $page) {
                $pageRow = SeoAuditPage::query()->create([
                    'seo_audit_id' => $audit->id,
                    'url' => (string) ($page['url'] ?? ''),
                    'status_code' => (int) ($page['status_code'] ?? 0),
                    'title' => $page['title'] ?? null,
                    'meta_description' => $page['meta_description'] ?? null,
                    'canonical_url' => $page['canonical_url'] ?? null,
                    'robots_meta' => $page['robots_meta'] ?? null,
                    'h1' => $page['h1'] ?? null,
                    'word_count' => (int) ($page['word_count'] ?? 0),
                    'internal_links_count' => (int) ($page['internal_links_count'] ?? 0),
                    'broken_links_count' => (int) ($page['broken_links_count'] ?? 0),
                    'page_type' => (string) ($page['page_type'] ?? 'site_page'),
                    'argusly_content_id' => ($page['argusly_content_id'] ?? null) ?: null,
                ]);

                $pageMap[(string) $pageRow->url] = $pageRow->id;
            }

            $issueCounts = ['info' => 0, 'warning' => 0, 'error' => 0];

            foreach ($issues as $issue) {
                $severity = (string) ($issue['severity'] ?? 'info');
                if (! isset($issueCounts[$severity])) {
                    $severity = 'info';
                }

                $issueCounts[$severity]++;

                SeoAuditIssue::query()->create([
                    'seo_audit_id' => $audit->id,
                    'seo_audit_page_id' => $pageMap[(string) ($issue['page_url'] ?? '')] ?? null,
                    'severity' => $severity,
                    'code' => (string) ($issue['code'] ?? 'unknown_issue'),
                    'title' => (string) ($issue['title'] ?? 'SEO issue'),
                    'description' => $issue['description'] ?? null,
                    'recommendation' => $issue['recommendation'] ?? null,
                    'context_json' => is_array($issue['context_json'] ?? null) ? $issue['context_json'] : null,
                ]);
            }

            $pagesCrawled = count($pages);
            $hasSuccessfulHtmlPages = collect($pages)
                ->contains(fn (array $page): bool => (bool) ($page['is_html'] ?? false)
                    && (int) ($page['status_code'] ?? 0) >= 200
                    && (int) ($page['status_code'] ?? 0) < 300
                    && trim((string) ($page['fetch_error_category'] ?? '')) === ''
                );

            if ($pagesCrawled > 0) {
                $quotaService->incrementUsage(
                    workspace: $site->workspace,
                    site: $site,
                    metric: PlanQuotaService::METRIC_AUDIT_PAGES_CRAWLED,
                    amount: $pagesCrawled,
                );
            }

            if ($pagesCrawled === 0 || ! $hasSuccessfulHtmlPages) {
                $failureMessage = $this->summarizeCrawlFailure($pages, $diagnostics);

                $audit->update([
                    'finished_at' => now(),
                    'status' => 'failed',
                    'pages_crawled' => $pagesCrawled,
                    'issue_counts' => $issueCounts,
                    'error_message' => $failureMessage,
                    'meta' => array_merge((array) $audit->meta, [
                        'crawl_source' => (string) ($result['crawl_source'] ?? 'unknown'),
                        'fetch_diagnostics' => $diagnostics,
                    ]),
                ]);

                if ($this->operationId) {
                    $operationService->markFailed(
                        operationId: $this->operationId,
                        errorMessage: $failureMessage,
                        errorCode: 'SEO_AUDIT_PUBLIC_CRAWL_FAILED',
                        resultPayload: [
                            'seo_audit_id' => $audit->id,
                            'pages_crawled' => $pagesCrawled,
                        ],
                    );
                }

                $webhookPublisher->publish(
                    workspace: $site->workspace,
                    eventType: 'seo_audit.failed',
                    payload: [
                        'seo_audit_id' => $audit->id,
                        'operation_id' => $this->operationId,
                        'error' => $failureMessage,
                        'pages_crawled' => $pagesCrawled,
                    ],
                    contentDestinationId: $this->contentDestinationId,
                    eventId: $this->operationId ?: (string) $audit->id,
                );

                return;
            }

            $audit->update([
                'finished_at' => now(),
                'status' => 'completed',
                'pages_crawled' => $pagesCrawled,
                'issue_counts' => $issueCounts,
                'error_message' => null,
                'meta' => array_merge((array) $audit->meta, [
                    'crawl_source' => (string) ($result['crawl_source'] ?? 'unknown'),
                    'fetch_diagnostics' => $diagnostics,
                ]),
            ]);

            if ($this->operationId) {
                $operationService->markCompleted($this->operationId, [
                    'seo_audit_id' => $audit->id,
                    'status' => 'completed',
                    'pages_crawled' => $pagesCrawled,
                    'issue_counts' => $issueCounts,
                ]);
            }
            $webhookPublisher->publish(
                workspace: $site->workspace,
                eventType: 'seo_audit.completed',
                payload: [
                    'seo_audit_id' => $audit->id,
                    'operation_id' => $this->operationId,
                    'pages_crawled' => $pagesCrawled,
                ],
                contentDestinationId: $this->contentDestinationId,
                eventId: $this->operationId ?: (string) $audit->id,
            );
        } catch (\Throwable $exception) {
            $audit->update([
                'finished_at' => now(),
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
            if ($this->operationId) {
                $operationService->markFailed(
                    operationId: $this->operationId,
                    errorMessage: $exception->getMessage(),
                    errorCode: 'SEO_AUDIT_FAILED',
                    resultPayload: ['seo_audit_id' => $audit->id],
                );
            }
            $webhookPublisher->publish(
                workspace: $site->workspace,
                eventType: 'seo_audit.failed',
                payload: [
                    'seo_audit_id' => $audit->id,
                    'operation_id' => $this->operationId,
                    'error' => $exception->getMessage(),
                ],
                contentDestinationId: $this->contentDestinationId,
                eventId: $this->operationId ?: (string) $audit->id,
            );
        }
    }

    /**
     * @param  array<int,array<string,mixed>>  $pages
     * @param  array<string,mixed>|null  $diagnostics
     */
    private function summarizeCrawlFailure(array $pages, ?array $diagnostics): string
    {
        $errorsByCategory = collect((array) data_get($diagnostics, 'errors_by_category', []));

        if ((int) $errorsByCategory->get('login_redirect', 0) > 0) {
            return 'Crawler was redirected to a login or verification page. Public pages must be reachable without auth or impersonation state.';
        }

        if ((int) $errorsByCategory->get('auth_error', 0) > 0) {
            return 'Crawler received an authentication or authorization response from a public URL.';
        }

        $firstPage = collect($pages)->first();
        $firstCategory = trim((string) data_get($firstPage, 'fetch_error_category', ''));
        if ($firstCategory !== '') {
            return match ($firstCategory) {
                'non_html' => 'Crawler reached the URL, but the response was not parseable HTML.',
                'redirect_blocked_cross_domain' => 'Crawler was redirected to a different host outside the allowed public site domain.',
                'server_error' => 'Crawler reached the public site, but the origin returned a server error.',
                default => 'Crawler could not fetch a crawlable public HTML response.',
            };
        }

        return 'Crawler did not receive any successful public HTML pages from the target site.';
    }
}
