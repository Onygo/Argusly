<?php

namespace App\Contracts;

use App\Models\DomainEvent;

interface DomainEventProjector
{
    public function project(DomainEvent $event): void;
}
