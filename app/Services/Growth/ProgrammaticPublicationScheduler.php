<?php

namespace App\Services\Growth;

use App\Enums\ContentDestinationType;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationPlanItem;
use App\Models\ProgrammaticPublicationReadiness;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProgrammaticPublicationScheduler
{
    public function schedulePlan(ProgrammaticPublicationPlan $plan): int
    {
        if (! in_array($plan->status, [
            ProgrammaticPublicationPlan::STATUS_APPROVED,
            ProgrammaticPublicationPlan::STATUS_SCHEDULING,
            ProgrammaticPublicationPlan::STATUS_SCHEDULED,
        ], true)) {
            throw new InvalidArgumentException('Only approved publication plans can be scheduled.');
        }

        $count = 0;
        $plan->items()
            ->with(['content', 'readiness', 'destination'])
            ->whereIn('status', [
                ProgrammaticPublicationPlanItem::STATUS_PLANNED,
                ProgrammaticPublicationPlanItem::STATUS_APPROVED,
                ProgrammaticPublicationPlanItem::STATUS_SCHEDULED,
                ProgrammaticPublicationPlanItem::STATUS_NEEDS_ATTENTION,
            ])
            ->get()
            ->each(function (ProgrammaticPublicationPlanItem $item) use (&$count): void {
                $this->scheduleItem($item);

                if ($item->refresh()->status === ProgrammaticPublicationPlanItem::STATUS_SCHEDULED) {
                    $count++;
                }
            });

        $this->refreshPlanStatus($plan);

        return $count;
    }

    public function scheduleItem(ProgrammaticPublicationPlanItem $item): ?ContentPublication
    {
        $item->loadMissing(['plan', 'content', 'readiness', 'destination']);
        $plan = $item->plan;

        if (! $plan instanceof ProgrammaticPublicationPlan || ! in_array($plan->status, [
            ProgrammaticPublicationPlan::STATUS_APPROVED,
            ProgrammaticPublicationPlan::STATUS_SCHEDULING,
            ProgrammaticPublicationPlan::STATUS_SCHEDULED,
        ], true)) {
            throw new InvalidArgumentException('Only approved publication plans can be scheduled.');
        }

        if (! in_array($item->status, [
            ProgrammaticPublicationPlanItem::STATUS_PLANNED,
            ProgrammaticPublicationPlanItem::STATUS_APPROVED,
            ProgrammaticPublicationPlanItem::STATUS_SCHEDULED,
            ProgrammaticPublicationPlanItem::STATUS_NEEDS_ATTENTION,
        ], true)) {
            throw new InvalidArgumentException('Only planned or approved publication plan items can be scheduled.');
        }

        if (! $item->content instanceof Content) {
            throw new InvalidArgumentException('Publication plan item has no linked content.');
        }

        if (! $item->readiness instanceof ProgrammaticPublicationReadiness || $item->readiness->status !== ProgrammaticPublicationReadiness::STATUS_APPROVED) {
            throw new InvalidArgumentException('Publication plan item readiness must be approved before scheduling.');
        }

        if ($plan->cadence !== ProgrammaticPublicationPlan::CADENCE_MANUAL && $item->planned_publish_at === null) {
            throw new InvalidArgumentException('Publication plan item requires a planned publish date for this cadence.');
        }

        $destination = $this->destinationFor($item);
        if (! $destination instanceof ContentDestination) {
            $this->markItemConflict($item, 'missing_destination', [
                'message' => 'Choose a destination before scheduling this plan.',
            ]);
            $this->refreshPlanStatus($plan);

            return null;
        }

        return DB::transaction(function () use ($item, $plan, $destination): ?ContentPublication {
            if ($existing = $this->existingPublicationForItem($item)) {
                if ($existing->isTerminalForProgrammaticScheduling()) {
                    $this->markItemConflict($item, 'existing_publication_terminal', [
                        'content_publication_id' => (string) $existing->id,
                        'delivery_status' => (string) $existing->delivery_status,
                        'remote_status' => (string) $existing->remote_status,
                    ]);
                    $this->refreshPlanStatus($plan);

                    return $existing->refresh();
                }

                $this->syncPublicationForSchedule($existing, $item, $plan, $destination);
                $this->markItemScheduled($item, $existing);
                $this->refreshPlanStatus($plan);

                return $existing->refresh();
            }

            if ($conflict = $this->conflictingActivePlanItem($item, $destination)) {
                $this->markItemConflict($item, 'content_already_scheduled_in_active_plan', [
                    'conflicting_plan_id' => (string) $conflict->programmatic_publication_plan_id,
                    'conflicting_plan_item_id' => (string) $conflict->id,
                ]);
                $this->refreshPlanStatus($plan);

                return ContentPublication::query()->whereKey($conflict->content_publication_id)->first();
            }

            $provider = $this->providerFor($destination, $item->content);
            $publication = ContentPublication::resolveForDelivery(
                (string) $item->content_id,
                (string) $destination->id,
                null,
                $provider,
                $item->content->language,
            );

            if ($publication->exists && $publication->isTerminalForProgrammaticScheduling()) {
                $this->markItemConflict($item, 'existing_publication_terminal', [
                    'content_publication_id' => (string) $publication->id,
                    'delivery_status' => (string) $publication->delivery_status,
                    'remote_status' => (string) $publication->remote_status,
                ]);
                $this->refreshPlanStatus($plan);

                return $publication->refresh();
            }

            $meta = array_replace_recursive((array) $publication->meta, [
                'source' => 'programmatic_publication_scheduler',
                'programmatic_publication_plan_id' => (string) $plan->id,
                'programmatic_publication_plan_item_id' => (string) $item->id,
                'programmatic_publication_readiness_id' => (string) $item->publication_readiness_id,
                'growth_program_id' => $plan->growth_program_id ? (string) $plan->growth_program_id : null,
                'programmatic_cluster_item_id' => $item->readiness?->programmatic_cluster_item_id ? (string) $item->readiness->programmatic_cluster_item_id : null,
                'planned_publish_at' => $item->planned_publish_at?->toIso8601String(),
                'publishes_live' => false,
            ]);

            $publication->forceFill([
                'destination_id' => $destination->id,
                'client_site_id' => null,
                'provider' => $provider,
                'remote_type' => $this->remoteTypeFor($item->content),
                'remote_status' => $item->planned_publish_at ? ContentPublication::REMOTE_SCHEDULED : ContentPublication::REMOTE_DRAFT,
                'delivery_status' => ContentPublication::STATUS_PENDING,
                'scheduled_publish_at' => $item->planned_publish_at,
                'meta' => $meta,
            ])->save();

            $this->markItemScheduled($item, $publication);
            $this->refreshPlanStatus($plan);

            return $publication->refresh();
        });
    }

    public function existingPublicationForItem(ProgrammaticPublicationPlanItem $item): ?ContentPublication
    {
        $publicationId = (string) data_get($item->metadata, 'content_publication_id', '');
        if ($item->content_publication_id) {
            $publication = ContentPublication::query()->whereKey($item->content_publication_id)->first();
            if ($publication) {
                return $publication;
            }
        }

        if ($publicationId !== '') {
            $publication = ContentPublication::query()->whereKey($publicationId)->first();
            if ($publication) {
                $this->restorePublicationLink($item, $publication);

                return $publication;
            }
        }

        $publication = ContentPublication::query()
            ->where('meta->programmatic_publication_plan_item_id', (string) $item->id)
            ->first();

        if ($publication) {
            $this->restorePublicationLink($item, $publication);
        }

        return $publication;
    }

    private function markItemScheduled(ProgrammaticPublicationPlanItem $item, ContentPublication $publication): void
    {
        $item->forceFill([
            'status' => ProgrammaticPublicationPlanItem::STATUS_SCHEDULED,
            'destination_id' => $publication->destination_id ?: $item->destination_id,
            'content_publication_id' => $publication->id,
            'metadata' => array_replace_recursive((array) $item->metadata, [
                'content_publication_id' => (string) $publication->id,
                'scheduled_at' => now()->toIso8601String(),
                'publishes_live' => false,
                'conflict' => null,
            ]),
        ])->save();
    }

    private function refreshPlanStatus(ProgrammaticPublicationPlan $plan): void
    {
        $plan->refreshCounters();
        $total = $plan->items()->count();
        $scheduled = $plan->items()->where('status', ProgrammaticPublicationPlanItem::STATUS_SCHEDULED)->count();
        $blocked = $plan->items()->whereIn('status', [
            ProgrammaticPublicationPlanItem::STATUS_CONFLICT,
            ProgrammaticPublicationPlanItem::STATUS_NEEDS_ATTENTION,
        ])->count();

        $plan->forceFill([
            'status' => $total > 0 && $blocked === 0 && $scheduled >= $total
                ? ProgrammaticPublicationPlan::STATUS_SCHEDULED
                : ProgrammaticPublicationPlan::STATUS_SCHEDULING,
        ])->save();
        $plan->refreshCounters();
    }

    private function destinationFor(ProgrammaticPublicationPlanItem $item): ?ContentDestination
    {
        if ($item->destination instanceof ContentDestination) {
            return $item->destination;
        }

        if ($item->plan?->destination instanceof ContentDestination) {
            return $item->plan->destination;
        }

        $activeDestinations = ContentDestination::query()
            ->where('workspace_id', $item->workspace_id)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->limit(2)
            ->get();

        return $activeDestinations->count() === 1 ? $activeDestinations->first() : null;
    }

    private function syncPublicationForSchedule(
        ContentPublication $publication,
        ProgrammaticPublicationPlanItem $item,
        ProgrammaticPublicationPlan $plan,
        ContentDestination $destination,
    ): void {
        $provider = $this->providerFor($destination, $item->content);
        $publication->forceFill([
            'destination_id' => $destination->id,
            'client_site_id' => null,
            'provider' => $provider,
            'remote_type' => $this->remoteTypeFor($item->content),
            'remote_status' => $item->planned_publish_at ? ContentPublication::REMOTE_SCHEDULED : ContentPublication::REMOTE_DRAFT,
            'delivery_status' => ContentPublication::STATUS_PENDING,
            'scheduled_publish_at' => $item->planned_publish_at,
            'meta' => array_replace_recursive((array) $publication->meta, [
                'source' => 'programmatic_publication_scheduler',
                'programmatic_publication_plan_id' => (string) $plan->id,
                'programmatic_publication_plan_item_id' => (string) $item->id,
                'programmatic_publication_readiness_id' => (string) $item->publication_readiness_id,
                'planned_publish_at' => $item->planned_publish_at?->toIso8601String(),
                'publishes_live' => false,
            ]),
        ])->save();
    }

    private function conflictingActivePlanItem(ProgrammaticPublicationPlanItem $item, ContentDestination $destination): ?ProgrammaticPublicationPlanItem
    {
        return ProgrammaticPublicationPlanItem::query()
            ->where('workspace_id', $item->workspace_id)
            ->where('content_id', $item->content_id)
            ->where('id', '!=', $item->id)
            ->where('destination_id', $destination->id)
            ->whereIn('status', [
                ProgrammaticPublicationPlanItem::STATUS_PLANNED,
                ProgrammaticPublicationPlanItem::STATUS_APPROVED,
                ProgrammaticPublicationPlanItem::STATUS_SCHEDULED,
            ])
            ->whereHas('plan', function ($query): void {
                $query->whereIn('status', ProgrammaticPublicationPlan::activeSchedulingStatuses());
            })
            ->first();
    }

    private function markItemConflict(ProgrammaticPublicationPlanItem $item, string $reason, array $context = []): void
    {
        $item->forceFill([
            'status' => $reason === 'missing_destination'
                ? ProgrammaticPublicationPlanItem::STATUS_NEEDS_ATTENTION
                : ProgrammaticPublicationPlanItem::STATUS_CONFLICT,
            'metadata' => array_replace_recursive((array) $item->metadata, [
                'conflict' => array_merge([
                    'reason' => $reason,
                    'recorded_at' => now()->toIso8601String(),
                ], $context),
            ]),
        ])->save();
    }

    private function restorePublicationLink(ProgrammaticPublicationPlanItem $item, ContentPublication $publication): void
    {
        if ((string) $item->content_publication_id === (string) $publication->id) {
            return;
        }

        $item->forceFill([
            'content_publication_id' => $publication->id,
            'metadata' => array_replace_recursive((array) $item->metadata, [
                'content_publication_id' => (string) $publication->id,
                'link_restored_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function providerFor(?ContentDestination $destination, Content $content): string
    {
        $type = ContentDestinationType::normalize($destination?->type?->value ?? $destination?->type);

        return match ($type) {
            ContentDestinationType::LARAVEL->value => ContentPublication::PROVIDER_LARAVEL,
            ContentDestinationType::API->value => ContentPublication::PROVIDER_API,
            ContentDestinationType::WORDPRESS->value => ContentPublication::PROVIDER_WORDPRESS,
            default => $content->client_site_id ? ContentPublication::PROVIDER_LARAVEL : ContentPublication::PROVIDER_API,
        };
    }

    private function remoteTypeFor(Content $content): string
    {
        return in_array((string) $content->type, ['seo_page', 'page'], true) ? 'page' : 'post';
    }
}
