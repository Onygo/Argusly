<?php

namespace App\Services\Journey;

final class JourneyAction
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $route,
        public readonly int $priority = 50,
    ) {
    }
}
