<?php

namespace App\Services\Api;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ArticleReadService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateForWorkspace(Workspace $workspace, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $status = trim((string) ($filters['status'] ?? ''));
        $publishStatus = trim((string) ($filters['publish_status'] ?? ''));
        $clientSiteId = trim((string) ($filters['client_site_id'] ?? ''));
        $destinationId = trim((string) ($filters['content_destination_id'] ?? ''));

        return Content::query()
            ->with([
                'clientSite:id,workspace_id,name,type,base_url,site_url',
                'contentDestination:id,name',
                'brief:id,content_id',
                'series:id,name',
                'seriesArticle:id,series_id,content_id,article_number,is_pillar',
                'publications' => fn ($query) => $query->latest('last_delivered_at')->latest('created_at'),
            ])
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($publishStatus !== '', fn ($query) => $query->where('publish_status', $publishStatus))
            ->when($clientSiteId !== '', fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->when($destinationId !== '', fn ($query) => $query->where('content_destination_id', $destinationId))
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForWorkspace(Workspace $workspace, string $articleId): Content
    {
        return Content::query()
            ->with([
                'clientSite:id,workspace_id,name,type,base_url,site_url',
                'contentDestination:id,name',
                'brief:id,content_id',
                'series:id,name',
                'seriesArticle:id,series_id,content_id,article_number,is_pillar',
                'publications' => fn ($query) => $query->latest('last_delivered_at')->latest('created_at'),
            ])
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->whereKey($articleId)
            ->firstOrFail();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Draft>
     */
    public function draftsForArticle(Workspace $workspace, string $articleId)
    {
        $article = $this->findForWorkspace($workspace, $articleId);

        return Draft::query()
            ->with(['brief:id,content_id,title'])
            ->where('content_id', $article->id)
            ->whereHas('clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->latest('created_at')
            ->get();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ContentPublication>
     */
    public function publicationsForArticle(Workspace $workspace, string $articleId)
    {
        $article = $this->findForWorkspace($workspace, $articleId);

        return ContentPublication::query()
            ->with(['destination:id,name,type', 'clientSite:id,name'])
            ->where('content_id', $article->id)
            ->whereHas('content.clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->latest('last_delivered_at')
            ->latest('created_at')
            ->get();
    }

    public function publicationForArticle(Workspace $workspace, string $articleId, string $publicationId): ContentPublication
    {
        $article = $this->findForWorkspace($workspace, $articleId);

        return ContentPublication::query()
            ->with(['destination:id,name,type', 'clientSite:id,name'])
            ->where('content_id', $article->id)
            ->where('id', $publicationId)
            ->whereHas('content.clientSite', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->firstOrFail();
    }
}
