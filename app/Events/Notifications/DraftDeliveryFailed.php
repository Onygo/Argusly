<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class DraftDeliveryFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $draftId,
        public readonly string $error
    ) {
    }
}
