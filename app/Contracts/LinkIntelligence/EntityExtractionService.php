<?php

namespace App\Contracts\LinkIntelligence;

use App\DTO\LinkIntelligence\EntityResult;
use App\Models\Draft;

interface EntityExtractionService
{
    public function extractEntities(Draft $article): EntityResult;
}
