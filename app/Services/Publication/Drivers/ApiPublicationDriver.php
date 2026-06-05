<?php

namespace App\Services\Publication\Drivers;

use App\Enums\DestinationCapability;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;
use App\Support\Publication\PublicationDestinationCapabilities;

class ApiPublicationDriver extends AbstractPublicationDestinationDriver
{
    public function type(): string
    {
        return 'api';
    }

    public function label(): string
    {
        return 'API';
    }

    public function icon(): string
    {
        return 'braces';
    }

    public function capabilities(): PublicationDestinationCapabilities
    {
        return PublicationDestinationCapabilities::only([
            DestinationCapability::PREVIEW_URL,
            DestinationCapability::MARKDOWN_PUSH,
        ]);
    }

    public function publish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult {
        return PublicationResult::failure(
            'API_DRIVER_NOT_IMPLEMENTED',
            'API destinations do not support this publishing flow yet.',
            retryable: false,
        );
    }

    public function verifyRemoteExists(
        ContentPublication $publication,
        ContentDestination $destination,
    ): VerificationResult {
        return VerificationResult::unknown('API destinations do not expose a generic remote verification flow.');
    }
}
