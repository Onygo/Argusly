<?php

namespace App\Contracts\LinkIntelligence;

use App\Models\Draft;
use Illuminate\Support\Collection;

interface LinkSuggestionService
{
    /**
     * @return Collection<int, \App\Models\LinkSuggestion>
     */
    public function generateSuggestions(Draft $source): Collection;
}
