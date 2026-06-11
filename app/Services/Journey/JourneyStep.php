<?php

namespace App\Services\Journey;

final class JourneyStep
{
    public function __construct(
        public readonly string $key,
        public readonly int $number,
        public readonly string $label,
        public readonly string $status,
        public readonly string $tooltip,
        public readonly ?string $route,
        public readonly ?string $blockingMessage = null,
    ) {
    }

    public function isCurrent(): bool
    {
        return $this->status === 'active';
    }
}
