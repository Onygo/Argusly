<?php

namespace App\Services\Agents;

use App\Models\ClientSite;
use App\Models\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class SiteContentScanScope
{
    /**
     * @param  array<int, string>  $statuses
     */
    public function query(
        ClientSite $site,
        array $statuses = ['published'],
        ?string $locale = null,
        ?int $organizationId = null,
        ?string $workspaceId = null,
        ?int $recentDays = null,
    ): Builder {
        $query = Content::query()
            ->with(['workspace', 'clientSite', 'drafts' => fn ($drafts) => $drafts->latest('created_at')->limit(5)])
            ->where('client_site_id', (string) $site->id)
            ->orderByDesc('updated_at')
            ->orderBy('id');

        if ($organizationId !== null) {
            $query->whereHas('workspace', fn (Builder $workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId));
        }

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId);
        }

        if ($locale !== null && trim($locale) !== '') {
            $query->where('language', trim($locale));
        }

        $normalizedStatuses = collect($statuses)
            ->map(fn (mixed $status): string => trim((string) $status))
            ->filter()
            ->values()
            ->all();

        if ($normalizedStatuses !== []) {
            $query->where(function (Builder $statusQuery) use ($normalizedStatuses): void {
                $statusQuery->whereIn('status', $normalizedStatuses)
                    ->orWhereIn('publish_status', $normalizedStatuses);
            });
        }

        if ($recentDays !== null && $recentDays > 0) {
            // Content does not keep a first-class published_at timestamp yet, so updated_at is the available recency proxy.
            $query->where('updated_at', '>=', Carbon::now()->subDays($recentDays));
        }

        return $query;
    }
}
