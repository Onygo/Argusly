<?php

namespace App\Services\SignalIntelligence;

use App\Enums\SignalCategory;
use App\Enums\SignalEntityType;
use App\Enums\SignalSourceType;
use App\Enums\SignalType;
use App\Models\LlmTrackingQueryRun;
use App\Models\SignalMention;
use App\Models\SignalSource;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LlmTrackingSignalAdapter
{
    public function __construct(
        private readonly SignalEntityResolver $entities,
        private readonly SignalEventIngestor $events,
    ) {}

    /**
     * @return array{runs_seen:int,mentions_created:int,events_created:int}
     */
    public function ingest(?Workspace $workspace = null): array
    {
        $stats = ['runs_seen' => 0, 'mentions_created' => 0, 'events_created' => 0];

        $this->query($workspace)->chunkById(100, function (Collection $runs) use (&$stats): void {
            $runs->each(function (LlmTrackingQueryRun $run) use (&$stats): void {
                $beforeMentions = SignalMention::query()->count();
                $beforeEvents = \App\Models\SignalEvent::query()->count();

                $this->ingestRun($run);

                $stats['runs_seen']++;
                $stats['mentions_created'] += max(0, SignalMention::query()->count() - $beforeMentions);
                $stats['events_created'] += max(0, \App\Models\SignalEvent::query()->count() - $beforeEvents);
            });
        });

        return $stats;
    }

    /**
     * @return array{mentions:Collection<int,SignalMention>,event:\App\Models\SignalEvent|null}
     */
    public function ingestRun(LlmTrackingQueryRun $run): array
    {
        $query = $run->trackingQuery()->first();
        $workspace = $query?->workspace()->first();

        if (! $query || ! $workspace) {
            return ['mentions' => collect(), 'event' => null];
        }

        $site = $query->site()->first();
        $source = $this->source($workspace);
        $mentions = collect();

        if ($run->brand_mentioned) {
            $brandName = $query->target_brand ?: $workspace->display_name;
            $mentions->push($this->mention($workspace, $run, $brandName, SignalMention::TYPE_BRAND, SignalEntityType::BRAND->value));
        }

        foreach ($this->competitorNames($run) as $competitorName) {
            $mentions->push($this->mention($workspace, $run, $competitorName, SignalMention::TYPE_COMPETITOR, SignalEntityType::COMPETITOR->value));
        }

        $event = $this->events->ingestEvent($workspace, [
            'client_site_id' => $site?->id,
            'signal_source_id' => $source->id,
            'category' => SignalCategory::AI_VISIBILITY->value,
            'type' => $run->brand_mentioned ? SignalType::BRAND_MENTIONED->value : SignalType::BRAND_MISSING->value,
            'topic' => $query->query_text,
            'entity_name' => $query->target_brand ?: $workspace->display_name,
            'entity_key' => $this->entities->entityKey($query->target_brand ?: $workspace->display_name),
            'signal_strength' => $run->ai_visibility_score ?? $run->presence_score ?? config('signal_intelligence.score_defaults.confidence', 50),
            'confidence_score' => $run->model_confidence_score ?? config('signal_intelligence.score_defaults.confidence', 50),
            'impact_score' => $run->owned_visibility_score ?? config('signal_intelligence.score_defaults.impact', 50),
            'risk_score' => $run->competitor_pressure_score,
            'observed_at' => $run->run_at ?? now(),
            'evidence' => $this->evidence($run),
            'metrics' => [
                'ai_visibility_score' => $run->ai_visibility_score,
                'competitor_share_score' => $run->competitor_share_score,
                'presence_score' => $run->presence_score,
            ],
            'metadata' => [
                'source' => 'llm_tracking',
                'llm_tracking_query_id' => $query->id,
                'llm_tracking_query_run_id' => $run->id,
                'provider' => $run->provider,
                'model' => $run->model,
            ],
            'dedupe_hash' => $this->events->dedupeHash([
                'source' => 'llm_tracking_run',
                'workspace_id' => $workspace->id,
                'run_id' => $run->id,
                'category' => SignalCategory::AI_VISIBILITY->value,
            ]),
        ], $site);

        return ['mentions' => $mentions->filter()->values(), 'event' => $event];
    }

    private function query(?Workspace $workspace): Builder
    {
        return LlmTrackingQueryRun::query()
            ->with('trackingQuery.workspace', 'trackingQuery.site')
            ->whereHas('trackingQuery', function (Builder $query) use ($workspace): void {
                if ($workspace) {
                    $query->where('workspace_id', $workspace->id);
                }
            });
    }

    private function source(Workspace $workspace): SignalSource
    {
        return SignalSource::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'type' => SignalSourceType::LLM_TRACKING->value,
                'name' => 'LLM Tracking',
            ],
            [
                'organization_id' => $workspace->organization_id,
                'status' => 'detected',
                'config' => ['adapter' => self::class],
            ]
        );
    }

    private function mention(Workspace $workspace, LlmTrackingQueryRun $run, string $name, string $mentionType, string $entityType): SignalMention
    {
        $query = $run->trackingQuery()->first();
        $site = $query?->site()->first();
        $entity = $this->entities->resolve($workspace, $entityType, $name, $site, ['source' => 'llm_tracking']);
        $dedupeHash = hash('sha256', implode('|', [$workspace->id, 'llm_tracking', $run->id, $mentionType, Str::lower($name)]));

        $mention = SignalMention::query()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'dedupe_hash' => $dedupeHash,
            ],
            [
                'organization_id' => $workspace->organization_id,
                'client_site_id' => $site?->id,
                'signal_entity_id' => $entity->id,
                'source_type' => SignalSourceType::LLM_TRACKING->value,
                'source_ref_type' => LlmTrackingQueryRun::class,
                'source_ref_id' => (string) $run->id,
                'mention_type' => $mentionType,
                'entity_type' => $entityType,
                'entity_name' => $name,
                'entity_key' => $entity->entity_key,
                'context' => $run->first_mention_context ?: Str::limit((string) $run->answer_text, 500),
                'sentiment_label' => $run->sentiment_label,
                'sentiment_score' => $run->sentiment_score,
                'position_score' => $run->position_score,
                'confidence_score' => $run->model_confidence_score ?? 90,
                'observed_at' => $run->run_at ?? now(),
                'metadata' => ['provider' => $run->provider, 'model' => $run->model],
            ]
        );

        $this->events->ingestMention($mention);

        return $mention;
    }

    /**
     * @return array<int,string>
     */
    private function competitorNames(LlmTrackingQueryRun $run): array
    {
        return collect(array_merge((array) $run->competitor_hits, (array) $run->detected_competitors))
            ->map(function (mixed $item): ?string {
                if (is_array($item)) {
                    return (string) ($item['name'] ?? $item['brand'] ?? $item['competitor'] ?? '');
                }

                return (string) $item;
            })
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique(fn (string $name): string => Str::lower($name))
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function evidence(LlmTrackingQueryRun $run): array
    {
        return collect((array) $run->sources)
            ->map(fn (mixed $source): array => is_array($source) ? $source : ['source' => $source])
            ->prepend([
                'type' => 'llm_tracking_run',
                'run_id' => $run->id,
                'context' => $run->first_mention_context,
            ])
            ->values()
            ->all();
    }
}
