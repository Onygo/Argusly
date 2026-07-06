<?php

namespace App\Services\PageIntelligence\PrValue;

use App\Models\PageSnapshot;

interface PrValueModel
{
    public function key(): string;

    public function version(): string;

    /**
     * @return array{score:float,estimated_value_amount:float|null,currency:string,confidence:float,breakdown:array<string,mixed>}
     */
    public function calculate(PageSnapshot $snapshot): array;
}
