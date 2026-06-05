<?php

namespace App\Contracts\LinkIntelligence;

use App\DTO\LinkIntelligence\ApplyOptions;
use App\Models\LinkSuggestion;

interface LinkApplyService
{
    public function applySuggestion(LinkSuggestion $suggestion, ApplyOptions $options): void;
}
