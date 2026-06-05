<?php

namespace App\Events\Agents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TranslationCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $sourceDraftId,
        public readonly string $translatedDraftId,
        public readonly ?string $sourceContentId = null,
        public readonly ?string $translatedContentId = null,
        public readonly ?string $targetLocale = null,
    ) {
    }
}

