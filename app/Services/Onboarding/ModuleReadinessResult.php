<?php

namespace App\Services\Onboarding;

class ModuleReadinessResult
{
    /**
     * @param array<int,ReadinessRequirement> $requirements
     * @param array<int,ReadinessRequirement> $missing_requirements
     * @param array<int,ReadinessAction> $recommended_actions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $status,
        public readonly int $progress,
        public readonly array $requirements,
        public readonly array $missing_requirements,
        public readonly array $recommended_actions,
        public readonly ?string $blocking_message = null,
        public readonly bool $is_active = false,
    ) {
    }
}
