<?php

namespace App\Support\Interaction;

use App\Models\MonitoredPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class MonitoredPageDataTable
{
    public function queryForWorkspace(int|string $workspaceId): Builder
    {
        return MonitoredPage::query()
            ->where('workspace_id', $workspaceId)
            ->with([
                'source:id,name,source_type,domain',
                'latestSnapshot',
                'latestSnapshot.contentExtraction:id,page_snapshot_id,summary,word_count,quality_score',
            ])
            ->withCount(['entities', 'mentions', 'topics', 'prValues'])
            ->latest('last_seen_at');
    }

    public function rows(Collection $pages): array
    {
        return $pages->map(fn (MonitoredPage $page): array => [
            'id' => $page->getKey(),
            'resource_key' => ResourceType::MONITORED_PAGE.':'.$page->getKey(),
            'title' => (string) ($page->title_current ?: $page->canonical_url ?: 'Monitored page'),
            'url' => (string) ($page->canonical_url ?: $page->first_seen_url),
            'domain' => (string) $page->domain,
            'source' => (string) ($page->source?->name ?: $page->source_type),
            'crawl_status' => (string) $page->crawl_status,
            'latest_snapshot_status' => (string) ($page->latestSnapshot?->http_status ?: $page->latestSnapshot?->error_code ?: 'none'),
            'summary' => (string) ($page->latestSnapshot?->contentExtraction?->summary ?: ''),
            'entities_count' => (int) ($page->entities_count ?? 0),
            'mentions_count' => (int) ($page->mentions_count ?? 0),
            'topics_count' => (int) ($page->topics_count ?? 0),
            'pr_values_count' => (int) ($page->pr_values_count ?? 0),
        ])->all();
    }
}
