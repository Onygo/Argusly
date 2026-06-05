<?php

namespace App\Support\Connectors\Results;

/**
 * Result object for publish/update/unpublish operations.
 *
 * Encapsulates the outcome of a publication operation including success/failure
 * status, remote identifiers, and error details.
 *
 * ## Usage
 *
 * ```php
 * // Success
 * return PublicationResult::success(
 *     remoteId: '12345',
 *     remoteUrl: 'https://example.com/post/12345',
 *     remoteStatus: 'published',
 * );
 *
 * // Failure
 * return PublicationResult::failure(
 *     errorCode: 'AUTH_FAILED',
 *     errorMessage: 'Invalid API key',
 *     retryable: false,
 * );
 * ```
 */
final class PublicationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $remoteId = null,
        public readonly ?string $remoteUrl = null,
        public readonly ?string $remoteType = null,
        public readonly ?string $remoteStatus = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = true,
        public readonly ?int $httpStatus = null,
        /** @var array<string, mixed> Additional metadata */
        public readonly array $meta = [],
    ) {}

    /**
     * Create a successful result.
     *
     * @param array<string, mixed> $meta
     */
    public static function success(
        ?string $remoteId = null,
        ?string $remoteUrl = null,
        ?string $remoteType = null,
        ?string $remoteStatus = 'published',
        ?int $httpStatus = null,
        array $meta = [],
    ): self {
        return new self(
            success: true,
            remoteId: $remoteId,
            remoteUrl: $remoteUrl,
            remoteType: $remoteType,
            remoteStatus: $remoteStatus,
            httpStatus: $httpStatus,
            meta: $meta,
        );
    }

    /**
     * Create a failed result.
     *
     * @param array<string, mixed> $meta
     */
    public static function failure(
        string $errorCode,
        string $errorMessage,
        bool $retryable = true,
        ?int $httpStatus = null,
        array $meta = [],
    ): self {
        return new self(
            success: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            retryable: $retryable,
            httpStatus: $httpStatus,
            meta: $meta,
        );
    }

    /**
     * Create a result for skipped operation (already up-to-date).
     *
     * @param array<string, mixed> $meta
     */
    public static function skipped(
        string $reason = 'Content already up-to-date',
        ?string $remoteId = null,
        ?string $remoteUrl = null,
        array $meta = [],
    ): self {
        return new self(
            success: true,
            remoteId: $remoteId,
            remoteUrl: $remoteUrl,
            errorCode: 'SKIPPED',
            errorMessage: $reason,
            meta: array_merge($meta, ['skipped' => true]),
        );
    }

    /**
     * Check if the operation was successful.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if the operation failed.
     */
    public function isFailure(): bool
    {
        return ! $this->success;
    }

    /**
     * Check if the operation was skipped.
     */
    public function isSkipped(): bool
    {
        return $this->meta['skipped'] ?? false;
    }

    /**
     * Check if the operation can be retried.
     */
    public function canRetry(): bool
    {
        return $this->isFailure() && $this->retryable;
    }

    /**
     * Check if a remote ID was returned.
     */
    public function hasRemoteId(): bool
    {
        return trim((string) $this->remoteId) !== '';
    }

    /**
     * Get the error details as a formatted string.
     */
    public function errorDetails(): ?string
    {
        if ($this->success) {
            return null;
        }

        $parts = [];

        if ($this->errorCode) {
            $parts[] = "[{$this->errorCode}]";
        }

        if ($this->errorMessage) {
            $parts[] = $this->errorMessage;
        }

        if ($this->httpStatus) {
            $parts[] = "(HTTP {$this->httpStatus})";
        }

        return implode(' ', $parts) ?: 'Unknown error';
    }

    /**
     * Convert to array for logging/storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'remote_id' => $this->remoteId,
            'remote_url' => $this->remoteUrl,
            'remote_type' => $this->remoteType,
            'remote_status' => $this->remoteStatus,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'retryable' => $this->retryable,
            'http_status' => $this->httpStatus,
            'meta' => $this->meta,
        ];
    }
}
