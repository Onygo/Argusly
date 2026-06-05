<?php

namespace App\Events\Agents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class ContentPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $contentId,
        public readonly ?string $draftId = null,
        public readonly ?string $source = null,
    ) {
    }
}

