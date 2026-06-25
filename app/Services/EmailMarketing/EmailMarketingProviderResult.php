<?php

namespace App\Services\EmailMarketing;

class EmailMarketingProviderResult
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public readonly ?string $remoteCampaignId = null,
        public readonly ?string $remoteTemplateId = null,
        public readonly ?string $remoteUrl = null,
        public readonly array $response = [],
    ) {}
}
