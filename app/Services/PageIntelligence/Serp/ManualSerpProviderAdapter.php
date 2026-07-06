<?php

namespace App\Services\PageIntelligence\Serp;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ManualSerpProviderAdapter implements SerpProviderAdapter
{
    public function observe(array $parameters): iterable
    {
        $results = $parameters['results'] ?? null;

        if ($results === null && array_key_exists('page_url', $parameters)) {
            $results = [$parameters];
        }

        if (! is_iterable($results)) {
            throw new InvalidArgumentException('Manual SERP provider requires a results array.');
        }

        foreach ($results as $result) {
            if ($result instanceof SerpObservationResult) {
                yield $result;

                continue;
            }

            if (! is_array($result)) {
                throw new InvalidArgumentException('Manual SERP provider results must be arrays or SERP observation DTOs.');
            }

            yield SerpObservationResult::fromArray(array_replace_recursive($this->defaults($parameters), $result));
        }
    }

    /**
     * @param array<string,mixed> $parameters
     * @return array<string,mixed>
     */
    private function defaults(array $parameters): array
    {
        $observedAt = $parameters['observed_at'] ?? $parameters['observedAt'] ?? null;

        return [
            'query' => $parameters['query'] ?? '',
            'locale' => $parameters['locale'] ?? null,
            'country' => $parameters['country'] ?? null,
            'device' => $parameters['device'] ?? 'desktop',
            'search_engine' => $parameters['search_engine'] ?? $parameters['searchEngine'] ?? 'google',
            'observed_at' => $observedAt instanceof Carbon ? $observedAt : $observedAt,
            'provider_key' => $parameters['provider_key'] ?? $parameters['providerKey'] ?? 'manual',
            'serp_query_set_id' => $parameters['serp_query_set_id'] ?? $parameters['serpQuerySetId'] ?? null,
            'serp_query_id' => $parameters['serp_query_id'] ?? $parameters['serpQueryId'] ?? null,
            'search_volume' => $parameters['search_volume'] ?? $parameters['searchVolume'] ?? null,
            'keyword_intent' => $parameters['keyword_intent'] ?? $parameters['keywordIntent'] ?? null,
            'metadata' => [
                'provider_mode' => 'manual_import',
            ],
        ];
    }
}
