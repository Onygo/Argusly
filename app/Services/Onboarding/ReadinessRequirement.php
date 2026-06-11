<?php

namespace App\Services\Onboarding;

class ReadinessRequirement
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly bool $completed,
        public readonly string $severity = 'required',
        public readonly ?string $action_label = null,
        public readonly ?string $action_route = null,
    ) {
    }
}
