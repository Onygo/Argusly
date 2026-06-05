<?php

namespace App\Services\CampaignClusterEngine;

class CampaignClusterPlan
{
    /**
     * @param  array<int,array<string,mixed>>  $items
     * @param  array<int,array<string,mixed>>  $dependencies
     * @param  array<string,mixed>  $sourceSignals
     */
    public function __construct(
        public readonly string $primaryEntity,
        public readonly string $primaryTopic,
        public readonly string $name,
        public readonly string $authorityStrategy,
        public readonly string $ctaStrategy,
        public readonly string $refreshCadence,
        public readonly array $items,
        public readonly array $dependencies,
        public readonly array $sourceSignals = [],
    ) {}
}
