<?php

namespace App\Services\Attribution\Models;

use App\Contracts\Attribution\AttributionModel;
use App\Models\AttributionConversion;
use App\Services\Attribution\Models\Concerns\AllocatesAttributionCredit;
use Illuminate\Support\Collection;

class TimeDecayAttributionModel implements AttributionModel
{
    use AllocatesAttributionCredit;

    public function key(): string
    {
        return 'time_decay';
    }

    public function label(): string
    {
        return 'Time decay';
    }

    public function allocate(Collection $matches, AttributionConversion $conversion, array $settings = []): Collection
    {
        $halfLifeDays = max(1.0, (float) ($settings['half_life_days'] ?? 7));
        $matches = $matches->sortBy(fn (array $match) => $match['touchpoint']->occurred_at)->values();
        $weights = $matches->map(function (array $match) use ($conversion, $halfLifeDays): float {
            $days = max(0, $match['touchpoint']->occurred_at->diffInDays($conversion->occurred_at, false));

            return pow(0.5, $days / $halfLifeDays);
        })->all();

        return $this->allocateWeights($matches, $weights, $this->key());
    }
}
