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
            ])
            ->get()
            ->each(function (ProgrammaticPublicationPlanItem $item) use (&$count): void {
                $this->scheduleItem($item);
                $count++;
            });

        $this->refreshPlanStatus($plan);

        return $count;
    }

    public function scheduleItem(ProgrammaticPublicationPlanItem $item): ContentPublication
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
        if (! $destination instanceof ContentDestination && ! $item->content->client_site_id) {
            throw new InvalidArgumentException('Publication plan item requires a destination before scheduling.');
        }

        return DB::transaction(function () use ($item, $plan, $destination): ContentPublication {
            if ($existing = $this->existingPublicationForItem($item)) {
                $this->markItemScheduled($item, $existing);
                $this->refreshPlanStatus($plan);

                return $existing->refresh();
            }

            $provider = $this->providerFor($destination, $item->content);
            $publication = ContentPublication::resolveForDelivery(
                (string) $item->content_id,
                $destination?->id ? (string) $destination->id : null,
                $destination ? null : ($item->content->client_site_id ? (string) $item->content->client_site_id : null),
                $provider,
                $item->content->language,
            );

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
                'destination_id' => $destination?->id,
                'client_site_id' => $destination ? null : $item->content->client_site_id,
                'provider' => $provider,
                'remote_type' => $this->remoteTypeFor($item->content),
                'remote_status' => $item->planned_publish_at ? ContentPublication::REMOTE_SCHEDULED : ContentPublication::REMOTE_DRAFT,
                'delivery_status' => ContentPublication::STATUS_PENDING,
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
        if ($publicationId !== '') {
            $publication = ContentPublication::query()->whereKey($publicationId)->first();
            if ($publication) {
                return $publication;
            }
        }

        return ContentPublication::query()
            ->where('meta->programmatic_publication_plan_item_id', (string) $item->id)
            ->first();
    }

    private function markItemScheduled(ProgrammaticPublicationPlanItem $item, ContentPublication $publication): void
    {
        $item->forceFill([
            'status' => ProgrammaticPublicationPlanItem::STATUS_SCHEDULED,
            'destination_id' => $publication->destination_id ?: $item->destination_id,
            'metadata' => array_replace_recursive((array) $item->metadata, [
                'content_publication_id' => (string) $publication->id,
                'scheduled_at' => now()->toIso8601String(),
                'publishes_live' => false,
            ]),
        ])->save();
    }

    private function refreshPlanStatus(ProgrammaticPublicationPlan $plan): void
    {
        $plan->refreshCounters();
        $total = $plan->items()->count();
        $scheduled = $plan->items()->where('status', ProgrammaticPublicationPlanItem::STATUS_SCHEDULED)->count();

        $plan->forceFill([
            'status' => $total > 0 && $scheduled >= $total
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

        if ($item->content?->contentDestination instanceof ContentDestination) {
            return $item->content->contentDestination;
        }

        return ContentDestination::query()
            ->where('workspace_id', $item->workspace_id)
            ->where('status', 'active')
            ->first();
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
