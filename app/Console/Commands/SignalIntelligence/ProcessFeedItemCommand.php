<?php

namespace App\Console\Commands\SignalIntelligence;

use App\Enums\SignalStatus;
use App\Models\SignalFeedItem;
use App\Models\SignalMention;
use App\Services\SignalIntelligence\MentionExtractionService;
use App\Services\SignalIntelligence\SignalEntityResolver;
use App\Services\SignalIntelligence\SignalEventIngestor;
use Illuminate\Console\Command;

class ProcessFeedItemCommand extends Command
{
    protected $signature = 'signal-intelligence:process-feed-item {id}';

    protected $description = 'Extract deterministic mentions and events from a Signal Intelligence feed item.';

    public function handle(
        MentionExtractionService $mentions,
        SignalEntityResolver $entities,
        SignalEventIngestor $events,
    ): int {
        if (! (bool) config('features.signal_intelligence', false)) {
            $this->warn('Signal Intelligence is disabled. No data processed.');

            return self::SUCCESS;
        }

        $feedItem = SignalFeedItem::query()->findOrFail((string) $this->argument('id'));
        $workspace = $feedItem->workspace()->firstOrFail();
        $clientSite = $feedItem->clientSite()->first();

        $feedItem->forceFill(['processing_status' => SignalStatus::PROCESSING->value, 'processing_error' => null])->save();

        try {
            $payloads = $mentions->extract($feedItem);
            $createdMentions = 0;
            $createdEvents = 0;

            foreach ($payloads as $payload) {
                $entity = $entities->resolve($workspace, (string) $payload['entity_type'], (string) $payload['entity_name'], $clientSite, [
                    'source' => 'feed_item_processing',
                ]);

                $payload['signal_entity_id'] = $entity->id;
                $payload['canonical_entity_id'] = $entity->id;
                $exists = SignalMention::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('dedupe_hash', $payload['dedupe_hash'])
                    ->exists();

                $mention = SignalMention::query()->firstOrCreate(
                    [
                        'workspace_id' => $workspace->id,
                        'dedupe_hash' => $payload['dedupe_hash'],
                    ],
                    $payload
                );

                if (! $exists) {
                    $createdMentions++;
                    $entities->incrementMentionCount($entity);
                }

                $eventExists = \App\Models\SignalEvent::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('signal_mention_id', $mention->id)
                    ->exists();

                $events->ingestMention($mention);

                if (! $eventExists) {
                    $createdEvents++;
                    $entities->incrementSignalCount($entity);
                }
            }

            $feedItem->forceFill(['processing_status' => SignalStatus::RESOLVED->value])->save();

            $this->info("Feed item processed. Mentions created: {$createdMentions}. Events created: {$createdEvents}.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $feedItem->forceFill([
                'processing_status' => SignalStatus::DISMISSED->value,
                'processing_error' => $exception->getMessage(),
            ])->save();

            $this->error('Feed item processing failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
