<?php

namespace App\Services\Onboarding;

use App\Models\Workspace;

interface ReadinessProvider
{
    public function key(): string;

    public function label(): string;

    public function description(): string;

    public function evaluate(Workspace $workspace): ModuleReadinessResult;
}
