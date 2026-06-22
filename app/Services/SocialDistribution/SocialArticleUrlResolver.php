<?php

namespace App\Services\SocialDistribution;

use App\Models\Content;
use App\Services\Seo\CanonicalUrlService;

class SocialArticleUrlResolver
{
    public function __construct(
        private readonly CanonicalUrlService $canonicals,
    ) {}

    public function forContent(Content $content, ?string $candidate = null): string
    {
        $candidate = trim((string) $candidate);

        $resolved = $this->canonicals->liveUrlForContent(
            $content,
            $candidate !== '' ? $candidate : null
        );

        return $resolved
            ?: ($candidate !== '' ? $candidate : trim((string) ($content->published_url ?: $content->seo_canonical)));
    }
}
