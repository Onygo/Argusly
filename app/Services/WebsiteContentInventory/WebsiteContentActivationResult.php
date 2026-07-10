<?php

namespace App\Services\WebsiteContentInventory;

use App\Models\Content;
use App\Models\ContentPageLink;

class WebsiteContentActivationResult
{
    public function __construct(
        public readonly Content $content,
        public readonly ContentPageLink $link,
        public readonly bool $contentCreated,
        public readonly bool $linkCreated,
        public readonly WebsitePageEligibilityResult $eligibility,
    ) {}
}
