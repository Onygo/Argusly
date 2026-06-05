<?php

namespace App\Events\Notifications;

use Illuminate\Foundation\Events\Dispatchable;

class SiteVerified
{
    use Dispatchable;

    public function __construct(
        public readonly string $siteId,
        public readonly string $channel
    ) {
    }
}
