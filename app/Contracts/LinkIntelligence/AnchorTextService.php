<?php

namespace App\Contracts\LinkIntelligence;

use App\Models\Draft;

interface AnchorTextService
{
    /**
     * @return array<int, string>
     */
    public function generateAnchorVariants(Draft $source, Draft $target): array;
}
