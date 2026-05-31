<?php

namespace App\Services\DomainEvents;

use App\Contracts\DomainEventProjector;
use App\Models\DomainEvent;
use App\Services\NotificationService;

class NotificationProjector implements DomainEventProjector
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function project(DomainEvent $event): void
    {
        $this->notifications->notifyForDomainEvent($event);
    }
}
