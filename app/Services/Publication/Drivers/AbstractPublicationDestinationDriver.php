<?php

namespace App\Services\Publication\Drivers;

use App\Contracts\Connectors\ConnectorContract;
use App\Contracts\Publication\PublicationDestinationDriverInterface;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;
use App\Support\Publication\PublicationDestinationCapabilities;

abstract class AbstractPublicationDestinationDriver implements PublicationDestinationDriverInterface
{
    public function __construct(
        protected readonly ?ConnectorContract $connector = null,
    ) {}

    public function capabilities(): PublicationDestinationCapabilities
    {
        if (! $this->connector) {
            return PublicationDestinationCapabilities::unsupported();
        }

        return PublicationDestinationCapabilities::fromConnectorCapabilities($this->connector->capabilities());
    }

    public function publish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult {
        return $this->connector?->publish($content, $destination, $publication, $draft, $options)
            ?? PublicationResult::failure('DESTINATION_UNSUPPORTED', 'Destination driver does not support publishing.', retryable: false);
    }

    public function republish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult {
        $options['force_delivery'] = true;

        return $this->publish($content, $destination, $publication, $draft, $options);
    }

    public function verifyRemoteExists(
        ContentPublication $publication,
        ContentDestination $destination,
    ): VerificationResult {
        return $this->connector?->verify($publication, $destination)
            ?? VerificationResult::unknown('Destination driver does not support remote verification.');
    }

    public function deleteRemote(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        array $options = [],
    ): PublicationResult {
        return $this->connector?->unpublish($content, $destination, $publication, $options)
            ?? PublicationResult::failure('DESTINATION_UNSUPPORTED', 'Destination driver does not support remote deletion.', retryable: false);
    }

    public function openRemoteUrl(?ContentPublication $publication = null, ?Content $content = null): ?string
    {
        return $publication?->remote_url ?? $content?->published_url;
    }

    public function buildPreviewUrl(Content $content, ?ContentPublication $publication = null): ?string
    {
        return $this->openRemoteUrl($publication, $content);
    }
}
