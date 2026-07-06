<?php

namespace App\Services\PageIntelligence;

use App\Enums\SignalCategory;
use App\Enums\SignalEntityType;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\MonitoredPage;
use App\Models\PageEntity;
use App\Models\PageMention;
use App\Models\PageSentiment;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\SignalEvent;
use App\Models\SignalMention;
use App\Services\SignalIntelligence\SignalEntityResolver;
use App\Services\SignalIntelligence\SignalEventIngestor;
use Illuminate\Support\Collection;

class PageSignalEmitter
{
    public function __construct(
        private readonly SignalEntityResolver $entityResolver,
        private readonly SignalEventIngestor $eventIngestor,
    ) {}

    /**
     * @return array{signal_mentions:int,signal_events:int}
     */
    public function emit(PageSnapshot $snapshot): array
    {
        $snapshot = $snapshot->loadMissing(['page.source', 'contentExtraction']);
        $workspace = $snapshot->workspace()->firstOrFail();
        $site = $snapshot->clientSite()->first();
        $events = collect();
        $signalMentions = collect();

        PageMention::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->with('entity')
            ->orderBy('position_start')
            ->get()
            ->each(function (PageMention $pageMention) use ($workspace, $site, $snapshot, $signalMentions, $events): void {
                $signalMention = $this->emitMention($snapshot, $pageMention, $workspace, $site);
                $signalMentions->push($signalMention);

                if ($signalMention->mention_type === SignalMention::TYPE_BRAND) {
                    $events->push($this->emitMentionEvent(
                        $snapshot,
                        $signalMention,
                        SignalCategory::BRAND_VISIBILITY,
                        SignalType::BRAND_MENTIONED,
                        SignalSeverity::INFO,
                        'new_page_brand_mention'
                    ));
                }

                if ($signalMention->mention_type === SignalMention::TYPE_COMPETITOR) {
                    $events->push($this->emitMentionEvent(
                        $snapshot,
                        $signalMention,
                        SignalCategory::COMPETITOR_VISIBILITY,
                        SignalType::COMPETITOR_MENTIONED,
                        SignalSeverity::LOW,
                        'page_competitor_mention'
                    ));
                }
            });

        PageSentiment::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->where('label', 'negative')
            ->whereIn('target_type', [PageSentiment::TARGET_BRAND, PageSentiment::TARGET_ENTITY])
            ->get()
            ->each(function (PageSentiment $sentiment) use ($snapshot, $workspace, $site, $events): void {
                if (! str_contains((string) $sentiment->target_key, 'brand') && $sentiment->target_type !== PageSentiment::TARGET_BRAND) {
                    return;
                }

                $entity = $sentiment->target_name
                    ? $this->entityResolver->resolve($workspace, SignalEntityType::BRAND->value, $sentiment->target_name, $site, $this->pageMetadata($snapshot))
                    : null;

                $events->push($this->eventIngestor->ingestEvent($workspace, [
                    'client_site_id' => $snapshot->client_site_id,
                    'signal_entity_id' => $entity?->id,
                    'category' => SignalCategory::RISK->value,
                    'type' => SignalType::NEGATIVE_SENTIMENT->value,
                    'severity' => SignalSeverity::HIGH->value,
                    'status' => SignalStatus::NEW->value,
                    'entity_name' => $sentiment->target_name,
                    'entity_key' => $entity?->entity_key ?: $sentiment->target_key,
                    'signal_strength' => min(100, abs((float) $sentiment->compound_score) * 100),
                    'confidence_score' => (float) $sentiment->confidence_score,
                    'risk_score' => min(100, abs((float) $sentiment->compound_score) * 100),
                    'observed_at' => $sentiment->analyzed_at ?? $snapshot->fetched_at ?? now(),
                    'evidence' => [[
                        'type' => 'page_sentiment',
                        'page_sentiment_id' => $sentiment->id,
                        'monitored_page_id' => $snapshot->monitored_page_id,
                        'page_snapshot_id' => $snapshot->id,
                        'label' => $sentiment->label,
                        'compound_score' => $sentiment->compound_score,
                        'explanation' => $sentiment->explanation,
                    ]],
                    'metadata' => $this->pageMetadata($snapshot, ['source' => 'page_intelligence_sentiment']),
                    'dedupe_hash' => $this->eventIngestor->dedupeHash([
                        'workspace_id' => $snapshot->workspace_id,
                        'source' => 'page_intelligence',
                        'page_snapshot_id' => $snapshot->id,
                        'type' => SignalType::NEGATIVE_SENTIMENT->value,
                        'target_key' => $sentiment->target_key,
                    ]),
                ], $site));
            });

        PageTopic::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->get()
            ->each(function (PageTopic $topic) use ($snapshot, $workspace, $site, $events): void {
                $events->push($this->eventIngestor->ingestEvent($workspace, [
                    'client_site_id' => $snapshot->client_site_id,
                    'category' => SignalCategory::TREND->value,
                    'type' => SignalType::TOPIC_TRENDING->value,
                    'severity' => SignalSeverity::INFO->value,
                    'status' => SignalStatus::NEW->value,
                    'topic' => $topic->topic_name,
                    'entity_name' => $topic->topic_name,
                    'entity_key' => $topic->topic_key,
                    'signal_strength' => (float) $topic->prominence_score,
                    'confidence_score' => (float) $topic->confidence_score,
                    'opportunity_score' => min(100, (float) $topic->confidence_score),
                    'observed_at' => $topic->classified_at ?? $snapshot->fetched_at ?? now(),
                    'evidence' => [[
                        'type' => 'page_topic',
                        'page_topic_id' => $topic->id,
                        'monitored_page_id' => $snapshot->monitored_page_id,
                        'page_snapshot_id' => $snapshot->id,
                        'evidence' => $topic->evidence_json,
                    ]],
                    'metadata' => $this->pageMetadata($snapshot, [
                        'source' => 'page_intelligence_topic',
                        'topic_type' => $topic->topic_type,
                    ]),
                    'dedupe_hash' => $this->eventIngestor->dedupeHash([
                        'workspace_id' => $snapshot->workspace_id,
                        'source' => 'page_intelligence',
                        'page_snapshot_id' => $snapshot->id,
                        'type' => SignalType::TOPIC_TRENDING->value,
                        'topic_key' => $topic->topic_key,
                    ]),
                ], $site));
            });

        $authorityEvent = $this->emitAuthorityEvent($snapshot, $events);
        if ($authorityEvent) {
            $events->push($authorityEvent);
        }

        return [
            'signal_mentions' => $signalMentions->unique('id')->count(),
            'signal_events' => $events->filter()->unique('id')->count(),
        ];
    }

    private function emitMention(PageSnapshot $snapshot, PageMention $pageMention, mixed $workspace, mixed $site): SignalMention
    {
        $entityType = $this->signalEntityType($pageMention->entity_type);
        $signalEntity = $this->entityResolver->resolve($workspace, $entityType, $pageMention->entity_name, $site, $this->pageMetadata($snapshot));
        $entityKey = $signalEntity->entity_key;
        $dedupeHash = hash('sha256', implode('|', [
            $snapshot->workspace_id,
            $snapshot->id,
            $pageMention->id,
            $pageMention->entity_type,
            $entityKey,
        ]));

        $signalMention = SignalMention::query()->firstOrCreate(
            [
                'workspace_id' => $snapshot->workspace_id,
                'dedupe_hash' => $dedupeHash,
            ],
            [
                'organization_id' => $snapshot->organization_id,
                'client_site_id' => $snapshot->client_site_id,
                'signal_entity_id' => $signalEntity->id,
                'source_type' => 'page_intelligence',
                'source_ref_type' => PageMention::class,
                'source_ref_id' => (string) $pageMention->id,
                'mention_type' => $this->signalMentionType($pageMention->mention_type),
                'entity_type' => $entityType,
                'entity_name' => $pageMention->entity_name,
                'entity_key' => $entityKey,
                'canonical_entity_id' => (string) $pageMention->page_entity_id,
                'url' => $snapshot->final_url ?: $snapshot->requested_url,
                'url_hash' => hash('sha256', (string) ($snapshot->final_url ?: $snapshot->requested_url)),
                'context' => $pageMention->evidence_snippet,
                'position_score' => $this->positionScore($pageMention),
                'confidence_score' => (float) $pageMention->confidence_score,
                'observed_at' => $pageMention->observed_at ?? $snapshot->fetched_at ?? now(),
                'metadata' => $this->pageMetadata($snapshot, [
                    'page_mention_id' => $pageMention->id,
                    'page_entity_id' => $pageMention->page_entity_id,
                    'matched_text' => $pageMention->matched_text,
                    'source' => 'page_intelligence_analysis',
                ]),
            ]
        );

        if ($signalMention->wasRecentlyCreated) {
            $this->entityResolver->incrementMentionCount($signalEntity);
        }

        return $signalMention->refresh();
    }

    private function emitMentionEvent(
        PageSnapshot $snapshot,
        SignalMention $mention,
        SignalCategory $category,
        SignalType $type,
        SignalSeverity $severity,
        string $reason
    ): SignalEvent {
        $event = $this->eventIngestor->ingestEvent($mention->workspace()->firstOrFail(), [
            'client_site_id' => $mention->client_site_id,
            'signal_mention_id' => $mention->id,
            'signal_entity_id' => $mention->signal_entity_id,
            'category' => $category->value,
            'type' => $type->value,
            'severity' => $severity->value,
            'status' => SignalStatus::NEW->value,
            'topic' => $mention->mention_type === SignalMention::TYPE_TOPIC ? $mention->entity_name : null,
            'entity_name' => $mention->entity_name,
            'entity_key' => $mention->entity_key,
            'signal_strength' => (float) $mention->confidence_score,
            'confidence_score' => (float) $mention->confidence_score,
            'observed_at' => $mention->observed_at ?? $snapshot->fetched_at ?? now(),
            'evidence' => [[
                'type' => 'page_signal_mention',
                'signal_mention_id' => $mention->id,
                'page_mention_id' => data_get($mention->metadata, 'page_mention_id'),
                'monitored_page_id' => $snapshot->monitored_page_id,
                'page_snapshot_id' => $snapshot->id,
                'context' => $mention->context,
            ]],
            'metadata' => $this->pageMetadata($snapshot, ['source' => 'page_intelligence', 'reason' => $reason]),
            'dedupe_hash' => $this->eventIngestor->dedupeHash([
                'workspace_id' => $snapshot->workspace_id,
                'source' => 'page_intelligence',
                'page_snapshot_id' => $snapshot->id,
                'type' => $type->value,
                'entity_key' => $mention->entity_key,
                'signal_mention_id' => $mention->id,
            ]),
        ], $mention->clientSite()->first());

        if ($event->wasRecentlyCreated && $mention->signalEntity) {
            $this->entityResolver->incrementSignalCount($mention->signalEntity);
        }

        return $event;
    }

    private function emitAuthorityEvent(PageSnapshot $snapshot, Collection $existingEvents): ?SignalEvent
    {
        /** @var MonitoredPage|null $page */
        $page = $snapshot->page;
        $source = $page?->source;

        if (! $source || ((float) $source->authority_score < 70 && (int) $source->trust_level < 7)) {
            return null;
        }

        $brandMention = $existingEvents
            ->first(fn (SignalEvent $event): bool => $event->type?->value === SignalType::BRAND_MENTIONED->value);

        if (! $brandMention) {
            return null;
        }

        return $this->eventIngestor->ingestEvent($snapshot->workspace()->firstOrFail(), [
            'client_site_id' => $snapshot->client_site_id,
            'signal_mention_id' => $brandMention->signal_mention_id,
            'signal_entity_id' => $brandMention->signal_entity_id,
            'category' => SignalCategory::BRAND_VISIBILITY->value,
            'type' => SignalType::BRAND_MENTIONED->value,
            'severity' => SignalSeverity::MEDIUM->value,
            'status' => SignalStatus::NEW->value,
            'entity_name' => $brandMention->entity_name,
            'entity_key' => $brandMention->entity_key,
            'signal_strength' => min(100, max((float) $source->authority_score, (int) $source->trust_level * 10)),
            'confidence_score' => 90,
            'impact_score' => min(100, max((float) $source->authority_score, (int) $source->trust_level * 10)),
            'observed_at' => $snapshot->fetched_at ?? now(),
            'evidence' => [[
                'type' => 'high_authority_source_mention',
                'monitored_source_id' => $source->id,
                'monitored_page_id' => $snapshot->monitored_page_id,
                'page_snapshot_id' => $snapshot->id,
                'authority_score' => $source->authority_score,
                'trust_level' => $source->trust_level,
            ]],
            'metadata' => $this->pageMetadata($snapshot, [
                'source' => 'page_intelligence_authority',
                'reason' => 'high_authority_source_mention',
            ]),
            'dedupe_hash' => $this->eventIngestor->dedupeHash([
                'workspace_id' => $snapshot->workspace_id,
                'source' => 'page_intelligence',
                'page_snapshot_id' => $snapshot->id,
                'type' => 'high_authority_source_mention',
                'entity_key' => $brandMention->entity_key,
            ]),
        ], $snapshot->clientSite()->first());
    }

    private function signalEntityType(string $pageEntityType): string
    {
        return match ($pageEntityType) {
            PageEntity::TYPE_BRAND => SignalEntityType::BRAND->value,
            PageEntity::TYPE_COMPETITOR => SignalEntityType::COMPETITOR->value,
            PageEntity::TYPE_TOPIC => SignalEntityType::TOPIC->value,
            default => SignalEntityType::SOURCE->value,
        };
    }

    private function signalMentionType(string $pageMentionType): string
    {
        return match ($pageMentionType) {
            PageEntity::TYPE_BRAND => SignalMention::TYPE_BRAND,
            PageEntity::TYPE_COMPETITOR => SignalMention::TYPE_COMPETITOR,
            PageEntity::TYPE_TOPIC => SignalMention::TYPE_TOPIC,
            default => SignalMention::TYPE_UNKNOWN,
        };
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function pageMetadata(PageSnapshot $snapshot, array $extra = []): array
    {
        return array_merge([
            'source' => 'page_intelligence',
            'monitored_page_id' => $snapshot->monitored_page_id,
            'page_snapshot_id' => $snapshot->id,
            'page_snapshot_number' => $snapshot->snapshot_number,
            'page_url' => $snapshot->final_url ?: $snapshot->requested_url,
        ], $extra);
    }

    private function positionScore(PageMention $mention): float
    {
        $position = $mention->position_start;

        if ($position === null) {
            return 0;
        }

        return round(max(0, 100 - min(100, ((int) $position / 1000) * 100)), 2);
    }
}
