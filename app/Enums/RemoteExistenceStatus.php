<?php

namespace App\Enums;

/**
 * Remote resource existence status.
 *
 * This represents whether the published resource exists on the remote
 * destination, separate from delivery status. Used for verification
 * and reconciliation of remote state.
 */
enum RemoteExistenceStatus: string
{
    // Existence not yet checked
    case UNKNOWN = 'unknown';

    // Verified to exist on remote
    case EXISTS = 'exists';

    // Verified to not exist (404)
    case MISSING = 'missing';

    // Exists but in trash/recycle bin
    case TRASHED = 'trashed';

    // Confirmed permanently deleted
    case DELETED = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::UNKNOWN => 'Unknown',
            self::EXISTS => 'Exists',
            self::MISSING => 'Missing',
            self::TRASHED => 'Trashed',
            self::DELETED => 'Deleted',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::UNKNOWN => 'slate',
            self::EXISTS => 'green',
            self::MISSING => 'red',
            self::TRASHED => 'orange',
            self::DELETED => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::UNKNOWN => 'question-mark-circle',
            self::EXISTS => 'check-circle',
            self::MISSING => 'x-circle',
            self::TRASHED => 'trash',
            self::DELETED => 'trash',
        };
    }

    public function isHealthy(): bool
    {
        return $this === self::EXISTS;
    }

    /**
     * Check if the existence status is healthy for a given destination type.
     *
     * For native destinations (Laravel), UNKNOWN is considered healthy since
     * we don't require external verification of internally-managed content.
     * For truly remote destinations (WordPress, API), only EXISTS is healthy.
     */
    public function isHealthyFor(?\App\Enums\ContentDestinationType $destinationType): bool
    {
        // EXISTS is always healthy
        if ($this === self::EXISTS) {
            return true;
        }

        // For native destinations, UNKNOWN is acceptable (no strict verification required)
        if ($destinationType?->isNativeDestination() === true && $this === self::UNKNOWN) {
            return true;
        }

        // All other combinations are not healthy
        return false;
    }

    public function isGone(): bool
    {
        return in_array($this, [self::MISSING, self::TRASHED, self::DELETED], true);
    }

    public function canRecover(): bool
    {
        return $this === self::TRASHED;
    }

    public function needsRecreation(): bool
    {
        return in_array($this, [self::MISSING, self::DELETED], true);
    }

    /**
     * Map from WordPress post status to existence status.
     */
    public static function fromWordPressStatus(?string $status): self
    {
        return match ($status) {
            'publish', 'draft', 'pending', 'private', 'future' => self::EXISTS,
            'trash' => self::TRASHED,
            null, '' => self::UNKNOWN,
            default => self::EXISTS,
        };
    }

    /**
     * Map from HTTP status code to existence status.
     */
    public static function fromHttpStatus(?int $status): self
    {
        return match (true) {
            $status === null => self::UNKNOWN,
            $status >= 200 && $status < 300 => self::EXISTS,
            $status === 404 => self::MISSING,
            $status === 410 => self::DELETED,
            default => self::UNKNOWN,
        };
    }
}
