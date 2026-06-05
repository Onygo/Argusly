<?php

namespace App\Services\Publication;

use App\Contracts\Publication\PublicationDestinationDriverInterface;
use App\Enums\ContentDestinationType;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Services\Publication\Drivers\ApiPublicationDriver;
use App\Services\Publication\Drivers\LaravelPublicationDriver;
use App\Services\Publication\Drivers\WordPressPublicationDriver;
use RuntimeException;

class PublicationDestinationDriverResolver
{
    public function __construct(
        private readonly WordPressPublicationDriver $wordPressDriver,
        private readonly LaravelPublicationDriver $laravelDriver,
        private readonly ApiPublicationDriver $apiDriver,
    ) {}

    public function resolveForDestination(ContentDestination $destination): PublicationDestinationDriverInterface
    {
        $rawType = $destination->rawTypeValue();
        $type = ContentDestinationType::fromNormalized($rawType);

        return match ($type) {
            ContentDestinationType::WORDPRESS => $this->wordPressDriver,
            ContentDestinationType::LARAVEL => $this->laravelDriver,
            ContentDestinationType::API => $this->apiDriver,
            default => throw new RuntimeException('Unknown destination type: '.($rawType ?? 'missing')),
        };
    }

    public function resolveForPublication(ContentPublication $publication): PublicationDestinationDriverInterface
    {
        return match (ContentDestinationType::fromNormalized($publication->provider)) {
            ContentDestinationType::WORDPRESS => $this->wordPressDriver,
            ContentDestinationType::LARAVEL => $this->laravelDriver,
            ContentDestinationType::API => $this->apiDriver,
            default => throw new RuntimeException('Unknown publication driver type: '.(string) $publication->provider),
        };
    }
}
