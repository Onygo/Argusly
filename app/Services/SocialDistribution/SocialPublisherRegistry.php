<?php

namespace App\Services\SocialDistribution;

use App\Enums\SocialPlatform;
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
        ];
    }

    public function forPlatform(string $platform): ?SocialPlatformPublisher
    {
        return $this->publishers()[$platform] ?? null;
    }
}
