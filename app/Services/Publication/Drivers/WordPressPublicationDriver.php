<?php

namespace App\Services\Publication\Drivers;

use App\Support\Connectors\WordPressPublicationConnector;

class WordPressPublicationDriver extends AbstractPublicationDestinationDriver
{
    public function __construct(
        WordPressPublicationConnector $connector,
    ) {
        parent::__construct($connector);
    }

    public function type(): string
    {
        return 'wordpress';
    }

    public function label(): string
    {
        return 'WordPress';
    }

    public function icon(): string
    {
        return 'globe';
    }
}
