<?php

namespace App\Services\SocialDistribution;

use App\Enums\SocialPlatform;
use App\Services\SocialDistribution\Publishers\InstagramPublisher;
use App\Services\SocialDistribution\Publishers\LinkedInPublisher;

class SocialPublisherRegistry
{
    /**
     * @return array<string, SocialPlatformPublisher>
     */
    public function publishers(): array
    {
        return [
            SocialPlatform::LINKEDIN->value => app(LinkedInPublisher::class),
            SocialPlatform::INSTAGRAM->value => app(InstagramPublisher::class),
        ];
    }

    public function forPlatform(string $platform): ?SocialPlatformPublisher
    {
        return $this->publishers()[$platform] ?? null;
    }
}
