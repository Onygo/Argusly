<?php

namespace App\Services\Onboarding;

class ReadinessAction
{
    public function __construct(
        public readonly string $label,
        public readonly string $description,
        public readonly ?string $route = null,
        public readonly string $type = 'secondary',
    ) {
    }
}
