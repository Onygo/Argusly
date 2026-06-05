<?php

namespace App\Services\SocialDistribution;

use App\Models\SocialPublication;

interface SocialPlatformPublisher
{
    public function platform(): string;

    public function publish(SocialPublication $publication): SocialPublishResult;
}
