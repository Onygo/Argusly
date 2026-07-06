<?php

namespace App\Services\PageIntelligence\Serp;

interface SerpProviderAdapter
{
    /**
     * @param array<string,mixed> $parameters
     * @return iterable<SerpObservationResult>
     */
    public function observe(array $parameters): iterable;
}
