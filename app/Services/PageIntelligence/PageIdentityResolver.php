<?php

namespace App\Services\PageIntelligence;

use App\Models\MonitoredPage;
use App\Models\Workspace;

class PageIdentityResolver
{
    public function resolve(Workspace|string $workspace, PageUrlNormalizationResult $url): ?MonitoredPage
    {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;

        if ($url->hasCanonicalIdentity) {
            $page = MonitoredPage::query()
                ->where('workspace_id', $workspaceId)
                ->where('canonical_url_hash', $url->canonicalUrlHash)
                ->first();

            if ($page !== null) {
                return $page;
            }
        }

        return MonitoredPage::query()
            ->where('workspace_id', $workspaceId)
            ->where('first_seen_url_hash', $url->firstSeenUrlHash)
            ->first();
    }
}
