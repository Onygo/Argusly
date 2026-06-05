<?php

namespace App\Services\Publication;

use App\Models\Content;
use App\Models\ContentDestination;
use App\Services\Integrations\LaravelConnectorDestinationResolver;

class LaravelPublicationDestinationResolver
{
    public function __construct(
        private readonly LaravelConnectorDestinationResolver $resolver,
    ) {}

    public function resolveForContent(Content $content): ?ContentDestination
    {
        return $this->resolver->resolveForContent($content);
    }
}
