<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\ClientSite;
use App\Models\SignalEvent;
use App\Models\SignalMention;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class SignalEventIngestor
{
    public function ingestMention(SignalMention $mention): SignalEvent
    {
        $category = match ($mention->mention_type) {
            SignalMention::TYPE_BRAND => SignalCategory::BRAND_VISIBILITY,
            SignalMention::TYPE_COMPETITOR => SignalCategory::COMPETITOR_VISIBILITY,
            default => SignalCategory::MENTION,
        };

        $type = match ($mention->mention_type) {
            SignalMention::TYPE_BRAND => SignalType::BRAND_MENTIONED,
            SignalMention::TYPE_COMPETITOR => SignalType::COMPETITOR_MENTIONED,
            SignalMention::TYPE_TOPIC => SignalType::TOPIC_TRENDING,
            default => SignalType::FEED_ITEM_PUBLISHED,
        };

        return $this->ingestEvent($mention->workspace()->firstOrFail(), [
            'client_site_id' => $mention->client_site_id,
            'signal_feed_item_id' => $mention->signal_feed_item_id,
            'signal_mention_id' => $mention->id,
            'signal_entity_id' => $mention->signal_entity_id,
            'category' => $category->value,
            'type' => $type->value,
            'severity' => SignalSeverity::INFO->value,
            'status' => SignalStatus::NEW->value,
            'topic' => $mention->mention_type === SignalMention::TYPE_TOPIC ? $mention->entity_name : null,
            'entity_name' => $mention->entity_name,
            'entity_key' => $mention->entity_key,
            'signal_strength' => $mention->confidence_score ?? config('signal_intelligence.score_defaults.confidence', 50),
            'confidence_score' => $mention->confidence_score ?? config('signal_intelligence.score_defaults.confidence', 50),
            'observed_at' => $mention->observed_at ?? now(),
            'evidence' => [[
                'type' => 'mention',
                'mention_id' => $mention->id,
                'feed_item_id' => $mention->signal_feed_item_id,
                'context' => $mention->context,
            ]],
            'metadata' => ['source' => 'mention_ingestion'],
            'dedupe_hash' => $this->dedupeHash([
                'workspace_id' => $mention->workspace_id,
                'source' => 'mention',
                'mention_id' => $mention->id,
                'type' => $type->value,
            ]),
        ], $mention->clientSite()->first());
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function ingestEvent(Workspace $workspace, array $payload, ?ClientSite $clientSite = null): SignalEvent
    {
        $category = $this->enumValue(SignalCategory::class, (string) ($payload['category'] ?? SignalCategory::MENTION->value));
        $type = $this->enumValue(SignalType::class, (string) ($payload['type'] ?? SignalType::FEED_ITEM_PUBLISHED->value));
        $severity = $this->enumValue(SignalSeverity::class, (string) ($payload['severity'] ?? SignalSeverity::INFO->value));
        $status = $this->enumValue(SignalStatus::class, (string) ($payload['status'] ?? SignalStatus::NEW->value));
        $confidence = (float) ($payload['confidence_score'] ?? config('signal_intelligence.score_defaults.confidence', 50));
        $signalStrength = (float) ($payload['signal_strength'] ?? $confidence);
        $dedupeHash = (string) ($payload['dedupe_hash'] ?? $this->dedupeHash([
            'workspace_id' => $workspace->id,
            'client_site_id' => $clientSite?->id ?? Arr::get($payload, 'client_site_id'),
            'category' => $category,
            'type' => $type,
            'entity_key' => Arr::get($payload, 'entity_key'),
            'topic' => Arr::get($payload, 'topic'),
            'source_ref_type' => Arr::get($payload, 'source_ref_type'),
            'source_ref_id' => Arr::get($payload, 'source_ref_id'),
        ]));

        return SignalEvent::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'dedupe_hash' => $dedupeHash,
            ],
            [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $clientSite?->id ?? Arr::get($payload, 'client_site_id'),
                'signal_source_id' => Arr::get($payload, 'signal_source_id'),
                'signal_feed_item_id' => Arr::get($payload, 'signal_feed_item_id'),
                'signal_mention_id' => Arr::get($payload, 'signal_mention_id'),
                'signal_entity_id' => Arr::get($payload, 'signal_entity_id'),
                'category' => $category,
                'type' => $type,
                'severity' => $severity,
                'status' => $status,
                'topic' => Arr::get($payload, 'topic'),
                'entity_name' => Arr::get($payload, 'entity_name'),
                'entity_key' => Arr::get($payload, 'entity_key'),
                'signal_strength' => $signalStrength,
                'confidence_score' => $confidence,
                'impact_score' => Arr::get($payload, 'impact_score', config('signal_intelligence.score_defaults.impact', 50)),
                'urgency_score' => Arr::get($payload, 'urgency_score'),
                'risk_score' => Arr::get($payload, 'risk_score'),
                'opportunity_score' => Arr::get($payload, 'opportunity_score'),
                'observed_at' => Arr::get($payload, 'observed_at', now()),
                'expires_at' => Arr::get($payload, 'expires_at'),
                'evidence' => Arr::wrap(Arr::get($payload, 'evidence', [])),
                'metrics' => Arr::get($payload, 'metrics', []),
                'metadata' => Arr::get($payload, 'metadata', []),
            ]
        );
    }

    /**
     * @param array<string,mixed> $parts
     */
    public function dedupeHash(array $parts): string
    {
        ksort($parts);

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    private function enumValue(string $enum, string $value): string
    {
        foreach ($enum::cases() as $case) {
            if ($case->value === $value) {
                return $value;
            }
        }

        throw new InvalidArgumentException("Invalid Signal Intelligence enum value [{$value}] for [{$enum}].");
    }
}
