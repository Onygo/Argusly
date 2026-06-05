<?php

namespace App\Services\Analytics;

use App\Models\Content;

class AnalyticsContentResolver
{
    public function resolve(string $siteId, string $urlKey): ?string
    {
        $siteId = trim($siteId);
        $urlKey = trim($urlKey);

        if ($siteId === '' || $urlKey === '') {
            return null;
        }

        $publishMatch = Content::query()
            ->where('client_site_id', $siteId)
            ->where('publish_url_key', $urlKey)
            ->value('id');

        if (is_string($publishMatch) && $publishMatch !== '') {
            return $publishMatch;
        }

        $canonicalMatch = Content::query()
            ->where('client_site_id', $siteId)
            ->where('canonical_url_key', $urlKey)
            ->value('id');

        if (is_string($canonicalMatch) && $canonicalMatch !== '') {
            return $canonicalMatch;
        }

        return null;
    }
}
