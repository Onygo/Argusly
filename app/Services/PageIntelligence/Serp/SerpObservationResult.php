<?php

namespace App\Services\PageIntelligence\Serp;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class SerpObservationResult
{
    /**
     * @param array<int|string,mixed> $serpFeatures
     * @param array<int|string,mixed> $competitorPresence
     * @param array<string,mixed> $rawPayload
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $query,
        public string $pageUrl,
        public ?string $locale = null,
        public ?string $country = null,
        public string $device = 'desktop',
        public string $searchEngine = 'google',
        public ?Carbon $observedAt = null,
        public string $resultType = 'organic',
        public ?int $position = null,
        public ?int $absolutePosition = null,
        public ?string $title = null,
        public ?string $snippet = null,
        public array $serpFeatures = [],
        public array $competitorPresence = [],
        public ?int $searchVolume = null,
        public ?string $keywordIntent = null,
        public ?float $clickPotential = null,
        public array $rawPayload = [],
        public ?string $providerKey = null,
        public ?string $serpQuerySetId = null,
        public ?string $serpQueryId = null,
        public array $metadata = [],
    ) {
        if (trim($query) === '') {
            throw new InvalidArgumentException('SERP observations require a query.');
        }

        if (trim($pageUrl) === '') {
            throw new InvalidArgumentException('SERP observations require a page URL.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            query: (string) ($payload['query'] ?? ''),
            pageUrl: (string) ($payload['page_url'] ?? $payload['pageUrl'] ?? ''),
            locale: self::nullableString($payload['locale'] ?? null),
            country: self::nullableString($payload['country'] ?? null),
            device: self::normalizedString($payload['device'] ?? 'desktop', 'desktop'),
            searchEngine: self::normalizedString($payload['search_engine'] ?? $payload['searchEngine'] ?? 'google', 'google'),
            observedAt: self::carbon($payload['observed_at'] ?? $payload['observedAt'] ?? null),
            resultType: self::normalizedString($payload['result_type'] ?? $payload['resultType'] ?? 'organic', 'organic'),
            position: self::nullableInt($payload['position'] ?? null),
            absolutePosition: self::nullableInt($payload['absolute_position'] ?? $payload['absolutePosition'] ?? $payload['position'] ?? null),
            title: self::nullableString($payload['title'] ?? null),
            snippet: self::nullableString($payload['snippet'] ?? null),
            serpFeatures: self::arrayValue($payload['serp_features'] ?? $payload['serp_features_json'] ?? $payload['serpFeatures'] ?? []),
            competitorPresence: self::arrayValue($payload['competitor_presence'] ?? $payload['competitor_presence_json'] ?? $payload['competitorPresence'] ?? []),
            searchVolume: self::nullableInt($payload['search_volume'] ?? $payload['searchVolume'] ?? null),
            keywordIntent: self::nullableString($payload['keyword_intent'] ?? $payload['keywordIntent'] ?? null),
            clickPotential: self::nullableFloat($payload['click_potential'] ?? $payload['clickPotential'] ?? null),
            rawPayload: self::arrayValue($payload['raw_payload'] ?? $payload['raw_payload_json'] ?? $payload['rawPayload'] ?? $payload),
            providerKey: self::nullableString($payload['provider_key'] ?? $payload['providerKey'] ?? null),
            serpQuerySetId: self::nullableString($payload['serp_query_set_id'] ?? $payload['serpQuerySetId'] ?? null),
            serpQueryId: self::nullableString($payload['serp_query_id'] ?? $payload['serpQueryId'] ?? null),
            metadata: self::arrayValue($payload['metadata'] ?? $payload['metadata_json'] ?? []),
        );
    }

    public function observedAtOrNow(): Carbon
    {
        return $this->observedAt ?? Carbon::now();
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function normalizedString(mixed $value, string $fallback): string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? $fallback : $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @return array<int|string,mixed>
     */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function carbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }
}
