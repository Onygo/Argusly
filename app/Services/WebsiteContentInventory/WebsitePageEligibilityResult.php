<?php

namespace App\Services\WebsiteContentInventory;

class WebsitePageEligibilityResult
{
    /**
     * @param  array<int,string>  $reasons
     * @param  array<string,mixed>  $signals
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly bool $campaignEligible,
        public readonly array $reasons,
        public readonly array $signals,
        public readonly ?string $normalizedUrl,
        public readonly ?string $urlHash,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'campaign_eligible' => $this->campaignEligible,
            'reasons' => $this->reasons,
            'signals' => $this->signals,
            'normalized_url' => $this->normalizedUrl,
            'url_hash' => $this->urlHash,
        ];
    }
}
