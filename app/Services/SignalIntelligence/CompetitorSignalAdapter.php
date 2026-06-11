<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalCategory;
use App\Enums\SignalEntityType;
use App\Enums\SignalSourceType;
use App\Enums\SignalType;
use App\Models\CompetitorContentItem;
use App\Models\CompetitorContentOpportunity;
use App\Models\CompetitorTopicSignal;
use App\Models\SignalMention;
use App\Models\SignalSource;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CompetitorSignalAdapter
{
    public function __construct(
        private readonly SignalEntityResolver $entities,
        private readonly SignalEventIngestor $events,
    ) {}

    /**
     * @return array{content_items_seen:int,topic_signals_seen:int,opportunities_seen:int,mentions_created:int,events_created:int}
     */
    public function ingest(?Workspace $workspace = null): array
    {
        $stats = [
            'content_items_seen' => 0,
            'topic_signals_seen' => 0,
            'opportunities_seen' => 0,
            'mentions_created' => 0,
            'events_created' => 0,
        ];

        $this->contentItems($workspace)->chunkById(100, function (Collection $items) use (&$stats): void {
            $items->each(function (CompetitorContentItem $item) use (&$stats): void {
                $created = $this->ingestContentItem($item);
                $stats['content_items_seen']++;
                $stats['mentions_created'] += $created['mention_created'] ? 1 : 0;
                $stats['events_created'] += $created['event_created'] ? 1 : 0;
            });
        });

        $this->topicSignals($workspace)->chunkById(100, function (Collection $signals) use (&$stats): void {
            $signals->each(function (CompetitorTopicSignal $signal) use (&$stats): void {
                $created = $this->ingestTopicSignal($signal);
                $stats['topic_signals_seen']++;
                $stats['events_created'] += $created ? 1 : 0;
            });
        });

        $this->opportunities($workspace)->chunkById(100, function (Collection $opportunities) use (&$stats): void {
            $opportunities->each(function (CompetitorContentOpportunity $opportunity) use (&$stats): void {
                $created = $this->ingestOpportunity($opportunity);
                $stats['opportunities_seen']++;
                $stats['events_created'] += $created ? 1 : 0;
            });
        });

        return $stats;
    }

    /**
     * @return array{mention_created:bool,event_created:bool}
     */
    public function ingestContentItem(CompetitorContentItem $item): array
    {
        $workspace = $item->workspace()->first();
        $competitor = $item->competitor()->first();

        if (! $workspace || ! $competitor) {
            return ['mention_created' => false, 'event_created' => false];
        }

        $source = $this->source($workspace);
        $entity = $this->entities->resolve($workspace, SignalEntityType::COMPETITOR->value, $competitor->name, $item->site()->first(), [
            'domain' => $competitor->domain,
            'source' => 'competitor_content_item',
        ]);
        $mentionHash = hash('sha256', implode('|', [$workspace->id, 'competitor_content_item', $item->id, $competitor->id]));
        $mentionBefore = SignalMention::query()->where('workspace_id', $workspace->id)->where('dedupe_hash', $mentionHash)->exists();

        $mention = SignalMention::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'dedupe_hash' => $mentionHash,
            ],
            [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $item->client_site_id,
                'signal_entity_id' => $entity->id,
                'source_type' => SignalSourceType::COMPETITOR->value,
                'source_ref_type' => CompetitorContentItem::class,
                'source_ref_id' => (string) $item->id,
                'mention_type' => SignalMention::TYPE_COMPETITOR,
                'entity_type' => SignalEntityType::COMPETITOR->value,
                'entity_name' => $competitor->name,
                'entity_key' => $entity->entity_key,
                'url' => $item->url,
                'url_hash' => $item->url_hash,
                'context' => $item->content_excerpt ?: $item->meta_description,
                'confidence_score' => 90,
                'observed_at' => $item->imported_at ?? now(),
                'metadata' => [
                    'content_item_id' => $item->id,
                    'content_type' => $item->content_type,
                    'topics' => $item->detected_topics,
                ],
            ]
        );

        $eventHash = $this->events->dedupeHash(['source' => 'competitor_content_item', 'workspace_id' => $workspace->id, 'item_id' => $item->id]);
        $eventBefore = \App\Models\SignalEvent::query()->where('workspace_id', $workspace->id)->where('dedupe_hash', $eventHash)->exists();

        $this->events->ingestEvent($workspace, [
            'client_site_id' => $item->client_site_id,
            'signal_source_id' => $source->id,
            'signal_mention_id' => $mention->id,
            'signal_entity_id' => $entity->id,
            'category' => SignalCategory::COMPETITOR_VISIBILITY->value,
            'type' => SignalType::COMPETITOR_MENTIONED->value,
            'topic' => $this->firstTopic($item->detected_topics) ?: $item->title,
            'entity_name' => $competitor->name,
            'entity_key' => $entity->entity_key,
            'signal_strength' => 70,
            'confidence_score' => 90,
            'observed_at' => $item->imported_at ?? now(),
            'evidence' => [[
                'type' => 'competitor_content_item',
                'content_item_id' => $item->id,
                'url' => $item->url,
                'title' => $item->title,
            ]],
            'metrics' => [
                'is_comparison_page' => $item->is_comparison_page,
                'has_answer_block_pattern' => $item->has_answer_block_pattern,
            ],
            'metadata' => ['source' => 'competitor_adapter'],
            'dedupe_hash' => $eventHash,
        ], $item->site()->first());

        return ['mention_created' => ! $mentionBefore, 'event_created' => ! $eventBefore];
    }

    public function ingestTopicSignal(CompetitorTopicSignal $signal): bool
    {
        $workspace = $signal->workspace()->first();

        if (! $workspace) {
            return false;
        }

        $hash = $this->events->dedupeHash(['source' => 'competitor_topic_signal', 'workspace_id' => $workspace->id, 'signal_id' => $signal->id]);
        $exists = \App\Models\SignalEvent::query()->where('workspace_id', $workspace->id)->where('dedupe_hash', $hash)->exists();

        $this->events->ingestEvent($workspace, [
            'client_site_id' => $signal->client_site_id,
            'signal_source_id' => $this->source($workspace)->id,
            'category' => SignalCategory::COMPETITOR_VISIBILITY->value,
            'type' => SignalType::COMPETITOR_CONTENT_SPIKE->value,
            'topic' => $signal->topic,
            'signal_strength' => $signal->opportunity_score ?? $signal->overlap_score ?? 50,
            'confidence_score' => 80,
            'opportunity_score' => $signal->opportunity_score,
            'observed_at' => $signal->last_seen_at ?? now(),
            'evidence' => [[
                'type' => 'competitor_topic_signal',
                'topic_signal_id' => $signal->id,
                'examples' => $signal->examples,
            ]],
            'metrics' => [
                'competitor_content_count' => $signal->competitor_content_count,
                'argusly_content_count' => $signal->argusly_content_count,
                'coverage_status' => $signal->coverage_status,
            ],
            'metadata' => ['source' => 'competitor_adapter'],
            'dedupe_hash' => $hash,
        ], $signal->site()->first());

        return ! $exists;
    }

    public function ingestOpportunity(CompetitorContentOpportunity $opportunity): bool
    {
        $workspace = $opportunity->workspace()->first();

        if (! $workspace) {
            return false;
        }

        $hash = $this->events->dedupeHash(['source' => 'competitor_opportunity', 'workspace_id' => $workspace->id, 'opportunity_id' => $opportunity->id]);
        $exists = \App\Models\SignalEvent::query()->where('workspace_id', $workspace->id)->where('dedupe_hash', $hash)->exists();

        $this->events->ingestEvent($workspace, [
            'client_site_id' => $opportunity->client_site_id,
            'signal_source_id' => $this->source($workspace)->id,
            'category' => SignalCategory::OPPORTUNITY->value,
            'type' => SignalType::CONTENT_GAP_SIGNAL->value,
            'topic' => $opportunity->topic,
            'entity_name' => $opportunity->competitor?->name,
            'entity_key' => $opportunity->competitor ? $this->entities->entityKey($opportunity->competitor->name) : null,
            'signal_strength' => $opportunity->priority_score ?? $opportunity->opportunity_score ?? 50,
            'confidence_score' => $opportunity->confidence_score ?? 75,
            'impact_score' => $opportunity->impact_score ?? 50,
            'opportunity_score' => $opportunity->priority_score,
            'observed_at' => $opportunity->last_seen_at ?? now(),
            'evidence' => [[
                'type' => 'competitor_content_opportunity',
                'opportunity_id' => $opportunity->id,
                'title' => $opportunity->title,
                'reason' => $opportunity->reason,
                'competitor_evidence' => $opportunity->competitor_evidence,
            ]],
            'metrics' => [
                'priority_score' => $opportunity->priority_score,
                'effort_score' => $opportunity->effort_score,
            ],
            'metadata' => ['source' => 'competitor_adapter', 'opportunity_type' => $opportunity->type],
            'dedupe_hash' => $hash,
        ], $opportunity->site()->first());

        return ! $exists;
    }

    private function contentItems(?Workspace $workspace): Builder
    {
        return CompetitorContentItem::query()
            ->with('workspace', 'site', 'competitor')
            ->when($workspace, fn (Builder $query) => $query->where('workspace_id', $workspace->id));
    }

    private function topicSignals(?Workspace $workspace): Builder
    {
        return CompetitorTopicSignal::query()
            ->with('workspace', 'site', 'competitor')
            ->when($workspace, fn (Builder $query) => $query->where('workspace_id', $workspace->id));
    }

    private function opportunities(?Workspace $workspace): Builder
    {
        return CompetitorContentOpportunity::query()
            ->with('workspace', 'site', 'competitor')
            ->when($workspace, fn (Builder $query) => $query->where('workspace_id', $workspace->id));
    }

    private function source(Workspace $workspace): SignalSource
    {
        return SignalSource::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'type' => SignalSourceType::COMPETITOR->value,
                'name' => 'Competitor Intelligence',
            ],
            [
                'organization_id' => $workspace->organization_id,
                'status' => 'detected',
                'config' => ['adapter' => self::class],
            ]
        );
    }

    /**
     * @param array<int,mixed>|null $topics
     */
    private function firstTopic(?array $topics): ?string
    {
        return collect($topics ?? [])
            ->map(function (mixed $topic): ?string {
                if (is_array($topic)) {
                    return (string) ($topic['name'] ?? $topic['topic'] ?? '');
                }

                return (string) $topic;
            })
            ->map(fn (string $topic): string => trim($topic))
            ->filter()
            ->first();
    }
}
