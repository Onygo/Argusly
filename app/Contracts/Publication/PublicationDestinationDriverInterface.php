<?php

namespace App\Contracts\Publication;

use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;
use App\Support\Publication\PublicationDestinationCapabilities;

interface PublicationDestinationDriverInterface
{
    public function type(): string;

    public function label(): string;

    public function icon(): string;

    public function capabilities(): PublicationDestinationCapabilities;

    /**
     * @param  array<string, mixed>  $options
     */
    public function publish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult;

    /**
     * @param  array<string, mixed>  $options
     */
    public function republish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult;

    public function verifyRemoteExists(
        ContentPublication $publication,
        ContentDestination $destination,
    ): VerificationResult;

    /**
     * @param  array<string, mixed>  $options
     */
    public function deleteRemote(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        array $options = [],
    ): PublicationResult;

    public function openRemoteUrl(?ContentPublication $publication = null, ?Content $content = null): ?string;

    public function buildPreviewUrl(Content $content, ?ContentPublication $publication = null): ?string;
}
