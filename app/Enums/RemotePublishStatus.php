<?php

namespace App\Enums;

/**
 * Remote resource publish status.
 *
 * This represents the publication state on the remote destination
 * (e.g., WordPress post status). Separate from delivery status and
 * existence status.
 */
enum RemotePublishStatus: string
{
    // Remote resource is in draft state
    case DRAFT = 'draft';

    // Remote resource is published/live
    case PUBLISHED = 'published';

    // Remote resource is scheduled for future publication
    case SCHEDULED = 'scheduled';

    // Remote resource is private/restricted
    case PRIVATE = 'private';

    // Remote resource is pending review
    case PENDING = 'pending';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PUBLISHED => 'Published',
            self::SCHEDULED => 'Scheduled',
            self::PRIVATE => 'Private',
            self::PENDING => 'Pending Review',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'slate',
            self::PUBLISHED => 'green',
            self::SCHEDULED => 'sky',
            self::PRIVATE => 'purple',
            self::PENDING => 'amber',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'pencil',
            self::PUBLISHED => 'globe-alt',
            self::SCHEDULED => 'clock',
            self::PRIVATE => 'lock-closed',
            self::PENDING => 'clock',
        };
    }

    public function isLive(): bool
    {
        return $this === self::PUBLISHED;
    }

    public function isVisible(): bool
    {
        return in_array($this, [self::PUBLISHED, self::PRIVATE], true);
    }

    /**
     * Map from WordPress post status to remote publish status.
     */
    public static function fromRemoteStatus(?string $status): self
    {
        return match (strtolower(trim((string) $status))) {
            'publish', 'published', 'live' => self::PUBLISHED,
            'draft', 'auto-draft' => self::DRAFT,
            'future', 'scheduled' => self::SCHEDULED,
            'private' => self::PRIVATE,
            'pending' => self::PENDING,
            default => self::DRAFT,
        };
    }

    public static function fromWordPressStatus(?string $status): self
    {
        return self::fromRemoteStatus($status);
    }
}
