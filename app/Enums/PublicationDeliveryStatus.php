<?php

namespace App\Enums;

/**
 * Remote delivery status for a content publication.
 *
 * This represents the state of delivery to a specific remote destination,
 * independent of the content's internal lifecycle status. A publication
 * can fail delivery while the content remains valid in PublishLayer.
 */
enum PublicationDeliveryStatus: string
{
    // Not yet attempted delivery
    case PENDING = 'pending';

    // Currently being delivered
    case PROCESSING = 'processing';

    // Successfully delivered to remote
    case DELIVERED = 'delivered';

    // Delivery attempt failed
    case FAILED = 'failed';

    // Remote resource was deleted after successful delivery
    case MISSING_REMOTE = 'missing_remote';

    // Local content changed since last delivery
    case OUT_OF_SYNC = 'out_of_sync';

    // Post was created/updated successfully but post-processing failed (partial success)
    // The remote post exists and is live, but some metadata may not have synced
    case PARTIAL_SUCCESS = 'partial_success';

    // Delivery result is uncertain - needs verification
    // Used when we receive an error but suspect the post may have been created
    case NEEDS_VERIFICATION = 'needs_verification';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Delivering',
            self::DELIVERED => 'Delivered',
            self::FAILED => 'Failed',
            self::MISSING_REMOTE => 'Missing',
            self::OUT_OF_SYNC => 'Out of Sync',
            self::PARTIAL_SUCCESS => 'Published (with warnings)',
            self::NEEDS_VERIFICATION => 'Needs Verification',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'slate',
            self::PROCESSING => 'amber',
            self::DELIVERED => 'green',
            self::FAILED => 'red',
            self::MISSING_REMOTE => 'orange',
            self::OUT_OF_SYNC => 'yellow',
            self::PARTIAL_SUCCESS => 'lime',
            self::NEEDS_VERIFICATION => 'amber',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::PROCESSING => 'arrow-path',
            self::DELIVERED => 'check-circle',
            self::FAILED => 'x-circle',
            self::MISSING_REMOTE => 'exclamation-triangle',
            self::OUT_OF_SYNC => 'arrow-path-rounded-square',
            self::PARTIAL_SUCCESS => 'check-badge',
            self::NEEDS_VERIFICATION => 'question-mark-circle',
        };
    }

    public function isSuccess(): bool
    {
        return in_array($this, [self::DELIVERED, self::PARTIAL_SUCCESS], true);
    }

    public function isPartialSuccess(): bool
    {
        return $this === self::PARTIAL_SUCCESS;
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::FAILED, self::MISSING_REMOTE], true);
    }

    public function isUncertain(): bool
    {
        return $this === self::NEEDS_VERIFICATION;
    }

    public function isInProgress(): bool
    {
        return $this === self::PROCESSING;
    }

    public function needsAttention(): bool
    {
        return in_array($this, [self::FAILED, self::MISSING_REMOTE, self::OUT_OF_SYNC, self::PARTIAL_SUCCESS, self::NEEDS_VERIFICATION], true);
    }

    public function canRetry(): bool
    {
        return in_array($this, [self::FAILED, self::MISSING_REMOTE, self::OUT_OF_SYNC, self::NEEDS_VERIFICATION], true);
    }

    public function canDeliver(): bool
    {
        return in_array($this, [self::PENDING, self::FAILED, self::MISSING_REMOTE, self::OUT_OF_SYNC, self::NEEDS_VERIFICATION], true);
    }

    /**
     * Whether this status indicates the remote post exists (or likely exists).
     */
    public function hasRemotePost(): bool
    {
        return in_array($this, [self::DELIVERED, self::PARTIAL_SUCCESS, self::OUT_OF_SYNC], true);
    }

    /**
     * Map legacy delivery_status values to the new enum.
     */
    public static function fromLegacyStatus(?string $status): self
    {
        return match ($status) {
            'pending' => self::PENDING,
            'processing', 'delivering' => self::PROCESSING,
            'delivered', 'synced' => self::DELIVERED,
            'failed' => self::FAILED,
            'missing_remote' => self::MISSING_REMOTE,
            'out_of_sync' => self::OUT_OF_SYNC,
            'partial_success', 'partial' => self::PARTIAL_SUCCESS,
            'needs_verification', 'verification_needed' => self::NEEDS_VERIFICATION,
            default => self::PENDING,
        };
    }
}
