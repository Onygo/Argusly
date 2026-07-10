<?php

namespace App\Services\WebsiteContentInventory;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Workspace;
use App\Services\PageIntelligence\PageIntelligencePipelineOrchestrator;
use App\Services\PageIntelligence\SubmitMonitoredPageAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class ObservedAnalyticsPageDiscoveryService
{
    public function __construct(
        private readonly SubmitMonitoredPageAction $submitPage,
        private readonly WebsitePageEligibilityService $eligibility,
        private readonly PageIntelligencePipelineOrchestrator $orchestrator,
    ) {}

    /**
     * @param  array{dry_run?:bool,chunk?:int,limit?:int,resume_after?:int|null}  $options
     */
    public function discoverForAnalyticsSite(AnalyticsSite $analyticsSite, array $options = []): ObservedAnalyticsPageDiscoveryResult
    {
        $analyticsSite->loadMissing('clientSite.workspace');

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $result = new ObservedAnalyticsPageDiscoveryResult(dryRun: $dryRun);

        if (! (bool) config('website_content_inventory.analytics_observed.enabled', true)) {
            $result->skip('analytics_observed_disabled');

            return $result;
        }

        if (! $analyticsSite->is_enabled || ! $analyticsSite->isVerified()) {
            $result->skip('analytics_site_not_enabled_or_verified');

            return $result;
        }

        $clientSite = $analyticsSite->clientSite;
        $workspace = $clientSite?->workspace;
        if (! $clientSite instanceof ClientSite || ! $workspace instanceof Workspace) {
            $result->skip('missing_client_site_or_workspace');

            return $result;
        }

        $allowedHosts = $this->allowedHosts($analyticsSite, $clientSite);
        if ($allowedHosts === []) {
            $result->skip('missing_verified_hosts');

            return $result;
        }

        $chunkSize = $this->chunkSize((int) ($options['chunk'] ?? 0));
        $limit = (int) ($options['limit'] ?? config('website_content_inventory.analytics_observed.max_urls_per_run', 500));
        $resumeAfter = isset($options['resume_after']) ? (int) $options['resume_after'] : null;
        $seen = [];
        $stopped = false;

        $query = $this->eventQuery($analyticsSite, $resumeAfter);

        $query->chunkById($chunkSize, function ($events) use (
            &$result,
            &$seen,
            &$stopped,
            $limit,
            $analyticsSite,
            $clientSite,
            $workspace,
            $allowedHosts,
            $dryRun,
        ): bool {
            foreach ($events as $event) {
                $result->processedEvents++;
                $result->lastEventId = (int) $event->id;

                if ($limit > 0 && $result->consideredUrls >= $limit) {
                    $stopped = true;

                    return false;
                }

                $candidate = $this->candidateUrl($event);
                if ($candidate === '') {
                    $result->skip('missing_url');

                    continue;
                }

                $normalized = $this->normalizeObservedUrl($candidate);
                if ($normalized === null) {
                    $result->skip('malformed_url');

                    continue;
                }

                $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));
                if (! $this->hostAllowed($host, $allowedHosts)) {
                    $result->skip('foreign_domain');

                    continue;
                }

                if (isset($seen[$normalized])) {
                    $result->skip('duplicate_url');

                    continue;
                }
                $seen[$normalized] = true;

                $result->consideredUrls++;

                $eligibility = $this->eligibility->evaluateUrl($normalized, 'page', [
                    'source_type' => (string) config('website_content_inventory.analytics_observed.source_type', 'analytics_observed'),
                    'analytics_site_id' => $analyticsSite->id,
                ]);

                if (! $eligibility->eligible) {
                    $result->exclude($eligibility->reasons[0] ?? 'ineligible');

                    continue;
                }

                if ($dryRun) {
                    continue;
                }

                try {
                    $submitResult = $this->submitPage->execute(
                        workspace: $workspace,
                        url: $normalized,
                        site: $clientSite,
                        sourceType: (string) config('website_content_inventory.analytics_observed.source_type', 'analytics_observed'),
                        pageType: 'page',
                        extraMetadata: [
                            'analytics_observed' => [
                                'analytics_site_id' => $analyticsSite->id,
                                'first_event_id' => (int) $event->id,
                                'last_event_id' => (int) $event->id,
                                'last_observed_at' => $this->eventObservedAt($event)?->toISOString(),
                                'event_title' => $event->title,
                                'stripped_query_string' => ! str_contains($normalized, '?'),
                            ],
                        ],
                    );

                    $result->submittedUrls++;
                    $submitResult->created ? $result->createdPages++ : $result->updatedPages++;

                    $page = $submitResult->page;
                    if (! $page->title_current && trim((string) $event->title) !== '') {
                        $page->forceFill(['title_current' => Str::limit((string) $event->title, 500, '')])->save();
                    }

                    if ((bool) config('website_content_inventory.analytics_observed.automatic_fetch_after_discovery', true)
                        && ($submitResult->created || $page->last_fetched_at === null)) {
                        $this->orchestrator->dispatchFetch($page, $page->first_seen_url);
                        $result->queuedFetches++;
                    }
                } catch (Throwable $exception) {
                    $result->fail($normalized, $exception->getMessage());
                }
            }

            return ! $stopped;
        }, 'id');

        if (! $dryRun) {
            $this->recordRun($analyticsSite, $result);
        }

        return $result;
    }

    private function eventQuery(AnalyticsSite $analyticsSite, ?int $resumeAfter): Builder
    {
        $includedPageTypes = (array) config('website_content_inventory.analytics_observed.included_page_types', [null, '', 'other_page']);
        $nonNullPageTypes = collect($includedPageTypes)
            ->filter(fn (mixed $type): bool => $type !== null)
            ->map(fn (mixed $type): string => (string) $type)
            ->all();
        $includeNullPageType = in_array(null, $includedPageTypes, true);

        return AnalyticsEvent::query()
            ->where('analytics_site_id', $analyticsSite->id)
            ->whereIn('event_type', (array) config('website_content_inventory.analytics_observed.page_event_types', ['page_view']))
            ->whereNull('content_id')
            ->when($resumeAfter !== null && $resumeAfter > 0, fn (Builder $query): Builder => $query->where('id', '>', $resumeAfter))
            ->where(function (Builder $query) use ($nonNullPageTypes, $includeNullPageType): void {
                if ($includeNullPageType) {
                    $query->whereNull('page_type');
                }

                if ($nonNullPageTypes !== []) {
                    $method = $includeNullPageType ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('page_type', $nonNullPageTypes);
                }
            })
            ->orderBy('id');
    }

    private function candidateUrl(AnalyticsEvent $event): string
    {
        foreach ([$event->canonical_url, $event->url] as $url) {
            $url = trim((string) $url);
            if ($url !== '') {
                return $url;
            }
        }

        $host = trim((string) $event->host);
        $path = trim((string) $event->path);
        if ($host === '' || $path === '') {
            return '';
        }

        return 'https://'.$host.'/'.ltrim($path, '/');
    }

    private function normalizeObservedUrl(string $url): ?string
    {
        $candidate = trim($url);
        if ($candidate === '') {
            return null;
        }

        if (str_contains($candidate, '://') && ! preg_match('#^https?://#i', $candidate)) {
            return null;
        }

        if (! preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://'.ltrim($candidate, '/');
        }

        $parts = parse_url($candidate);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        $host = trim($host, '[]');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = $this->queryForSubmission((string) ($parts['query'] ?? ''));

        $normalized = $scheme.'://'.$host.$path;
        if ($query !== '') {
            $normalized .= '?'.$query;
        }

        return strlen($normalized) > 2048 ? null : $normalized;
    }

    private function queryForSubmission(string $query): string
    {
        if (! (bool) config('website_content_inventory.analytics_observed.preserve_allowlisted_query_parameters', false)) {
            return '';
        }

        $pairs = [];
        parse_str($query, $pairs);
        if ($pairs === []) {
            return '';
        }

        $allowlist = collect((array) config('website_content_inventory.analytics_observed.query_parameter_allowlist', []))
            ->map(fn (mixed $key): string => strtolower(trim((string) $key)))
            ->filter()
            ->all();

        $pairs = collect($pairs)
            ->filter(fn (mixed $value, string|int $key): bool => in_array(strtolower((string) $key), $allowlist, true))
            ->all();

        if ($pairs === []) {
            return '';
        }

        ksort($pairs);

        return http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim(trim($path) ?: '/', '/');
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    /**
     * @return array<int,string>
     */
    private function allowedHosts(AnalyticsSite $analyticsSite, ClientSite $clientSite): array
    {
        $hosts = [];
        foreach (array_merge(
            (array) $analyticsSite->allowed_domains,
            (array) $clientSite->allowed_domains,
            [$clientSite->base_url, $clientSite->site_url]
        ) as $value) {
            $host = $this->hostFromDomainOrUrl((string) $value);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function hostFromDomainOrUrl(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        return trim($value, '*. ');
    }

    /**
     * @param  array<int,string>  $allowedHosts
     */
    private function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function eventObservedAt(AnalyticsEvent $event): ?Carbon
    {
        return $event->event_time ?: $event->received_at ?: $event->created_at;
    }

    private function chunkSize(int $requested): int
    {
        $configured = (int) config('website_content_inventory.analytics_observed.chunk_size', 250);
        $max = max(1, (int) config('website_content_inventory.analytics_observed.max_chunk_size', 2000));

        return max(1, min($requested > 0 ? $requested : $configured, $max));
    }

    private function recordRun(AnalyticsSite $analyticsSite, ObservedAnalyticsPageDiscoveryResult $result): void
    {
        $flags = (array) ($analyticsSite->flags ?? []);
        $flags['website_content_inventory']['last_observed_page_discovery'] = array_merge($result->toArray(), [
            'finished_at' => Carbon::now()->toISOString(),
        ]);

        $analyticsSite->forceFill(['flags' => $flags])->save();
    }
}
