<?php

namespace App\Contracts\Connectors;

use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Support\Connectors\ConnectorCapabilities;
use App\Support\Connectors\Results\HealthCheckResult;
use App\Support\Connectors\Results\PublicationResult;
use App\Support\Connectors\Results\VerificationResult;

/**
 * Contract for content publication connectors.
 *
 * Connectors are responsible for publishing content to remote destinations
 * (WordPress, Laravel, API endpoints, etc.) and managing the publication lifecycle.
 *
 * ## Implementation Guidelines
 *
 * 1. **Idempotency**: Operations should be safe to retry. Use remote_id to
 *    determine if content already exists before creating.
 *
 * 2. **Error Handling**: Return appropriate result objects with error details.
 *    Don't throw exceptions for expected failures (network errors, auth failures).
 *
 * 3. **ContentPublication**: This is the canonical source for tracking publications.
 *    Connectors should not directly update Content or ContentPublishTarget.
 *
 * 4. **Capabilities**: Use capabilities() to declare what operations are supported.
 *    The orchestration layer will check capabilities before calling methods.
 *
 * @see \App\Support\Connectors\ConnectorCapabilities
 * @see \App\Models\ContentPublication
 */
interface ConnectorContract
{
    /**
     * Get the connector type identifier.
     *
     * This should match ContentPublication::PROVIDER_* constants.
     *
     * @return string One of: wordpress, laravel, api, webhook
     */
    public function type(): string;

    /**
     * Get the connector's capabilities.
     *
     * Capabilities declare what operations this connector supports.
     * The orchestration layer uses this to determine available actions.
     */
    public function capabilities(): ConnectorCapabilities;

    /**
     * Publish content to the remote destination.
     *
     * Creates a new resource on the remote system. If the content already
     * exists (has a remote_id in the publication), this may behave as an
     * update depending on the connector implementation.
     *
     * @param Content $content The content to publish
     * @param ContentDestination $destination The target destination configuration
     * @param ContentPublication $publication The publication record to update
     * @param Draft|null $draft Optional draft to use for content data
     * @param array<string, mixed> $options Additional options (status, scheduled_at, etc.)
     * @return PublicationResult Result with success status, remote_id, remote_url
     */
    public function publish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult;

    /**
     * Update existing content on the remote destination.
     *
     * Updates a resource that already exists on the remote system.
     * Requires publication to have a valid remote_id.
     *
     * @param Content $content The content to update
     * @param ContentDestination $destination The target destination configuration
     * @param ContentPublication $publication The publication record (must have remote_id)
     * @param Draft|null $draft Optional draft to use for content data
     * @param array<string, mixed> $options Additional options
     * @return PublicationResult Result with success status
     */
    public function update(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        ?Draft $draft = null,
        array $options = [],
    ): PublicationResult;

    /**
     * Unpublish (delete/trash) content on the remote destination.
     *
     * Removes or trashes the resource on the remote system.
     * Requires publication to have a valid remote_id.
     *
     * @param Content $content The content to unpublish
     * @param ContentDestination $destination The target destination configuration
     * @param ContentPublication $publication The publication record (must have remote_id)
     * @param array<string, mixed> $options Additional options (soft_delete, etc.)
     * @return PublicationResult Result with success status
     */
    public function unpublish(
        Content $content,
        ContentDestination $destination,
        ContentPublication $publication,
        array $options = [],
    ): PublicationResult;

    /**
     * Verify that published content still exists on the remote destination.
     *
     * Checks if the remote resource exists and optionally validates its state.
     *
     * @param ContentPublication $publication The publication record to verify
     * @param ContentDestination $destination The target destination configuration
     * @return VerificationResult Result with existence status and remote state
     */
    public function verify(
        ContentPublication $publication,
        ContentDestination $destination,
    ): VerificationResult;

    /**
     * Check the health of the destination connection.
     *
     * Tests connectivity, authentication, and basic functionality.
     *
     * @param ContentDestination $destination The destination to check
     * @return HealthCheckResult Result with health status and diagnostics
     */
    public function healthCheck(ContentDestination $destination): HealthCheckResult;

    /**
     * Map content fields to the connector's expected format.
     *
     * Transforms Content/Draft data into the payload format expected
     * by the remote system.
     *
     * @param Content $content The content to map
     * @param Draft|null $draft Optional draft for additional data
     * @param array<string, mixed> $options Additional mapping options
     * @return array<string, mixed> The mapped payload
     */
    public function mapFields(Content $content, ?Draft $draft = null, array $options = []): array;
}
