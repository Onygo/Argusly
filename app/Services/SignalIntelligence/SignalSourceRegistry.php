<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalSourceType;
use InvalidArgumentException;

class SignalSourceRegistry
{
    /**
     * @return array<string,array{capabilities:array<int,string>,config:array<string,mixed>}>
     */
    public function definitions(): array
    {
        return [
            SignalSourceType::RSS_FEED->value => [
                'capabilities' => ['supports_feed_items', 'supports_mentions'],
                'config' => ['requires_url' => true, 'polling' => false],
            ],
            SignalSourceType::WEBSITE_FEED->value => [
                'capabilities' => ['supports_feed_items', 'supports_mentions', 'supports_domains'],
                'config' => ['requires_url' => true, 'polling' => false],
            ],
            SignalSourceType::LLM_TRACKING->value => [
                'capabilities' => ['supports_mentions', 'supports_ai_visibility', 'supports_competitors'],
                'config' => ['mutates_source' => false],
            ],
            SignalSourceType::ANALYTICS->value => [
                'capabilities' => ['supports_engagement', 'supports_domains'],
                'config' => ['mutates_source' => false],
            ],
            SignalSourceType::COMPETITOR->value => [
                'capabilities' => ['supports_competitors', 'supports_topics', 'supports_opportunities'],
                'config' => ['mutates_source' => false],
            ],
            SignalSourceType::MANUAL->value => [
                'capabilities' => ['supports_mentions', 'supports_events'],
                'config' => ['requires_user' => false],
            ],
            SignalSourceType::API->value => [
                'capabilities' => ['supports_feed_items', 'supports_mentions', 'supports_events'],
                'config' => ['requires_auth' => true],
            ],
            SignalSourceType::LINKEDIN->value => [
                'capabilities' => ['supports_mentions', 'supports_engagement'],
                'config' => ['enabled_for_ingestion' => false],
            ],
            SignalSourceType::WEBHOOK->value => [
                'capabilities' => ['supports_feed_items', 'supports_mentions', 'supports_events'],
                'config' => ['requires_signature' => true],
            ],
            SignalSourceType::SEARCH_TREND->value => [
                'capabilities' => ['supports_topics', 'supports_trends'],
                'config' => ['polling' => false],
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function sourceTypes(): array
    {
        return array_keys($this->definitions());
    }

    public function isAllowed(SignalSourceType|string $type): bool
    {
        return array_key_exists($this->normalizeType($type), $this->definitions());
    }

    /**
     * @return array<int,string>
     */
    public function capabilities(SignalSourceType|string $type): array
    {
        $key = $this->assertAllowed($type);

        return $this->definitions()[$key]['capabilities'];
    }

    /**
     * @return array<string,mixed>
     */
    public function defaultConfig(SignalSourceType|string $type): array
    {
        $key = $this->assertAllowed($type);

        return $this->definitions()[$key]['config'];
    }

    public function assertAllowed(SignalSourceType|string $type): string
    {
        $key = $this->normalizeType($type);

        if (! $this->isAllowed($key)) {
            throw new InvalidArgumentException("Unsupported Signal Intelligence source type [{$key}].");
        }

        return $key;
    }

    private function normalizeType(SignalSourceType|string $type): string
    {
        return $type instanceof SignalSourceType ? $type->value : trim($type);
    }
}
