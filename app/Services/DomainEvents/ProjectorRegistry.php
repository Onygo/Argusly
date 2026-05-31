<?php

namespace App\Services\DomainEvents;

use App\Contracts\DomainEventProjector;
use App\Models\DomainEvent;
use App\Models\DomainEventProjectorRun;
use Illuminate\Support\Collection;
use Throwable;

class ProjectorRegistry
{
    /**
     * @param  iterable<int, DomainEventProjector>  $projectors
     */
    public function __construct(private readonly iterable $projectors = []) {}

    public function project(DomainEvent $event): void
    {
        foreach ($this->projectors() as $projector) {
            $this->projectOnce($event, $projector);
        }
    }

    /**
     * @return Collection<int, DomainEventProjector>
     */
    public function projectors(): Collection
    {
        return collect($this->projectors);
    }

    private function projectOnce(DomainEvent $event, DomainEventProjector $projector): void
    {
        $projectorName = $projector::class;
        $run = DomainEventProjectorRun::query()->firstOrCreate(
            [
                'event_uuid' => $event->uuid,
                'projector' => $projectorName,
            ],
            [
                'domain_event_id' => $event->id,
                'status' => 'running',
                'started_at' => now(),
            ],
        );

        if ($run->status === 'completed') {
            return;
        }

        $run->forceFill([
            'domain_event_id' => $event->id,
            'status' => 'running',
            'error' => null,
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
        ])->save();

        try {
            $projector->project($event);

            $run->forceFill([
                'status' => 'completed',
                'error' => null,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }
}
