<?php

namespace App\Contracts;

use App\Models\IntelligenceSignal;

interface SignalProducer
{
    public function supports(object $event): bool;

    public function produce(object $event): ?IntelligenceSignal;
}
