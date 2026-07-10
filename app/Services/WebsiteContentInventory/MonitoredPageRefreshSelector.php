<?php

namespace App\Services\WebsiteContentInventory;

use App\Models\MonitoredPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MonitoredPageRefreshSelector
{
    /**
     * @param  array{workspace_id?:string|null,client_site_id?:string|null,limit?:int|null}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $now = Carbon::now();
        $staleCutoff = $now->copy()->subHours(max(1, (int) config('website_content_inventory.refresh_intervals.observed_page_refresh_hours', 24)));
        $temporaryFailureCutoff = $now->copy()->subHours(max(1, (int) config('website_content_inventory.refresh_intervals.temporary_failure_retry_hours', 6)));

        $query = MonitoredPage::query()
            ->with(['latestSnapshot', 'contentPageLinks'])
            ->whereNull('deleted_at')
            ->where(function (Builder $query) use ($staleCutoff, $temporaryFailureCutoff): void {
                $query->whereNull('last_fetched_at')
                    ->orWhere('last_fetched_at', '<=', $staleCutoff)
                    ->orWhere(function (Builder $failed) use ($temporaryFailureCutoff): void {
                        $failed->where('crawl_status', MonitoredPage::CRAWL_STATUS_FAILED)
                            ->where(function (Builder $date) use ($temporaryFailureCutoff): void {
                                $date->whereNull('last_fetched_at')
                                    ->orWhere('last_fetched_at', '<=', $temporaryFailureCutoff);
                            });
                    });
            })
            ->where(function (Builder $query): void {
                $query->whereNull('metadata_json->inventory->review_override')
                    ->orWhereNotIn('metadata_json->inventory->review_override', ['excluded', 'exclude', 'ineligible']);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('metadata_json->inventory->availability_status')
                    ->orWhere('metadata_json->inventory->availability_status', '!=', 'persistent_not_found');
            });

        if (! empty($filters['workspace_id'])) {
            $query->where('workspace_id', $filters['workspace_id']);
        }

        if (! empty($filters['client_site_id'])) {
            $query->where('client_site_id', $filters['client_site_id']);
        }

        $query->orderByRaw($this->priorityExpression())
            ->orderByRaw('last_fetched_at is not null')
            ->orderByDesc(DB::raw('COALESCE(last_seen_at, first_seen_at, created_at)'))
            ->orderBy('id');

        $limit = (int) ($filters['limit'] ?? 0);
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    private function priorityExpression(): string
    {
        return "case
            when last_fetched_at is null then 100
            when crawl_status = '".MonitoredPage::CRAWL_STATUS_FAILED."' then 80
            when exists (
                select 1 from content_page_links
                where content_page_links.monitored_page_id = monitored_pages.id
                and content_page_links.deleted_at is null
            ) then 60
            when source_type in ('analytics_observed', 'xml_sitemap') then 40
            else 10
        end desc";
    }
}
