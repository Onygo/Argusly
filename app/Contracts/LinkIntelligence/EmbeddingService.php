<?php

namespace App\Contracts\LinkIntelligence;

use App\DTO\LinkIntelligence\EmbeddingResult;
use App\Models\Draft;

interface EmbeddingService
{
    public function buildEmbeddingForArticle(Draft $article): EmbeddingResult;
}
