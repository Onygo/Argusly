<?php

namespace App\Jobs;

use App\Models\DomainEvent;
use App\Services\DomainEvents\ProjectorRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProjectDomainEventJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $domainEventId) {}

    public function handle(ProjectorRegistry $projectors): void
    {
        $event = DB::transaction(fn () => DomainEvent::query()
            ->whereKey($this->domainEventId)
            ->lockForUpdate()
            ->firstOrFail());

        if ($event->processed_at !== null) {
            return;
        }

        $projectors->project($event);

        DB::transaction(function (): void {
            $event = DomainEvent::query()
                ->whereKey($this->domainEventId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->processed_at === null) {
                $event->forceFill(['processed_at' => now()])->save();
            }
        });
    }
}
