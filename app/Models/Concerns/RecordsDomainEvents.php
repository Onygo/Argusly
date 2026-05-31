<?php

namespace App\Models\Concerns;

use App\Models\DomainEvent;
use App\Models\User;
use App\Services\DomainEventService;
use Illuminate\Database\Eloquent\Model;

trait RecordsDomainEvents
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function recordDomainEvent(string $eventType, ?User $actor = null, ?array $payload = null): DomainEvent
    {
        /** @var Model $this */
        return app(DomainEventService::class)->recordForSubject($eventType, $this, $actor, $payload);
    }
}
