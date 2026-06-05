<?php

namespace App\Contracts\LinkIntelligence;

use App\DTO\LinkIntelligence\LinkScore;
use App\Models\Draft;

interface LinkRelevanceService
{
    public function scoreCandidate(Draft $source, Draft $target): LinkScore;
}
