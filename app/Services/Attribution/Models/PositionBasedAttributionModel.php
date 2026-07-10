<?php

namespace App\Services\Attribution\Models;

use App\Contracts\Attribution\AttributionModel;
use App\Models\AttributionConversion;
use App\Services\Attribution\Models\Concerns\AllocatesAttributionCredit;
use Illuminate\Support\Collection;

class PositionBasedAttributionModel implements AttributionModel
{
    use AllocatesAttributionCredit;

    public function key(): string
    {
        return 'position_based';
    }

    public function label(): string
    {
        return 'Position based';
    }

    public function allocate(Collection $matches, AttributionConversion $conversion, array $settings = []): Collection
    {
        unset($conversion, $settings);

        $matches = $matches->sortBy(fn (array $match) => $match['touchpoint']->occurred_at)->values();
        $count = $matches->count();

        if ($count <= 2) {
            return $this->allocateWeights($matches, array_fill(0, $count, 1.0), $this->key());
        }

        $weights = array_fill(0, $count, 0.2 / max(1, $count - 2));
        $weights[0] = 0.4;
        $weights[$count - 1] = 0.4;

        return $this->allocateWeights($matches, $weights, $this->key());
    }
}
