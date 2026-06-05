<?php

namespace App\Events\LinkIntelligence;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArticleSignalsRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $articleId,
    ) {}
}
