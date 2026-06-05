<?php

namespace App\Services\Publication\Drivers;

use App\Support\Connectors\LaravelConnector;

class LaravelPublicationDriver extends AbstractPublicationDestinationDriver
{
    public function __construct(
        LaravelConnector $connector,
    ) {
        parent::__construct($connector);
    }

    public function type(): string
    {
        return 'laravel';
    }

    public function label(): string
    {
        return 'Laravel';
    }

    public function icon(): string
    {
        return 'server';
    }
}
