<?php

namespace App\Enums;

/**
 * PublishLayer internal content lifecycle status.
 *
 * This represents the content's state within PublishLayer, independent of
 * any remote delivery or publication status. A content item can be "delivered"
 * in PublishLayer terms even if the remote publication failed.
 */
enum ContentLifecycleStatus: string
{
    // Content idea - initial planning stage
    case IDEA = 'idea';

    // Content is being briefed/planned
    case BRIEF = 'brief';

    // Draft is being generated or reviewed
    case DRAFT = 'draft';

    // Content is under review
    case REVIEW = 'review';

    // Content is approved and ready for delivery
    case APPROVED = 'approved';

    // Content is scheduled for future delivery
    case SCHEDULED = 'scheduled';

    // Content has been published to at least one destination
    case PUBLISHED = 'published';

    // Content needs refresh/update
    case REFRESH_NEEDED = 'refresh_needed';

    // Content is archived (no longer active)
    case ARCHIVED = 'archived';

    // Legacy alias - kept for backward compatibility
    case READY_TO_DELIVER = 'ready_to_deliver';

    // Legacy alias - kept for backward compatibility
    case DELIVERED = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::IDEA => 'Idea',
            self::BRIEF => 'Brief',
            self::DRAFT => 'Draft',
            self::REVIEW => 'In Review',
            self::APPROVED, self::READY_TO_DELIVER => 'Approved',
            self::SCHEDULED => 'Scheduled',
            self::PUBLISHED, self::DELIVERED => 'Published',
            self::REFRESH_NEEDED => 'Needs Refresh',
            self::ARCHIVED => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::IDEA => 'slate',
            self::BRIEF => 'slate',
            self::DRAFT => 'amber',
            self::REVIEW => 'purple',
            self::APPROVED, self::READY_TO_DELIVER => 'emerald',
            self::SCHEDULED => 'sky',
            self::PUBLISHED, self::DELIVERED => 'green',
            self::REFRESH_NEEDED => 'orange',
            self::ARCHIVED => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::IDEA => 'lightbulb',
            self::BRIEF => 'file-text',
            self::DRAFT => 'pencil',
            self::REVIEW => 'eye',
            self::APPROVED, self::READY_TO_DELIVER => 'check-circle',
            self::SCHEDULED => 'clock',
            self::PUBLISHED, self::DELIVERED => 'globe',
            self::REFRESH_NEEDED => 'refresh-cw',
            self::ARCHIVED => 'archive',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::IDEA, self::BRIEF, self::DRAFT, self::REVIEW, self::REFRESH_NEEDED], true);
    }

    public function isDeliverable(): bool
    {
        return in_array($this, [self::APPROVED, self::READY_TO_DELIVER, self::SCHEDULED], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::ARCHIVED;
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Get allowed transitions from this stage.
     *
     * @return array<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::IDEA => [self::BRIEF, self::ARCHIVED],
            self::BRIEF => [self::IDEA, self::DRAFT, self::ARCHIVED],
            self::DRAFT => [self::BRIEF, self::REVIEW, self::ARCHIVED],
            self::REVIEW => [self::DRAFT, self::APPROVED, self::ARCHIVED],
            self::APPROVED, self::READY_TO_DELIVER => [self::REVIEW, self::SCHEDULED, self::PUBLISHED, self::ARCHIVED],
            self::SCHEDULED => [self::APPROVED, self::PUBLISHED, self::ARCHIVED],
            self::PUBLISHED, self::DELIVERED => [self::REFRESH_NEEDED, self::ARCHIVED],
            self::REFRESH_NEEDED => [self::DRAFT, self::PUBLISHED, self::ARCHIVED],
            self::ARCHIVED => [self::IDEA, self::DRAFT], // Can restore to idea or draft
        };
    }

    /**
     * Check if transition to target stage is allowed.
     */
    public function canTransitionTo(self $target): bool
    {
        // Same stage is always allowed (no-op)
        if ($this === $target) {
            return true;
        }

        // Normalize legacy values
        $normalizedTarget = $target->normalized();

        return in_array($normalizedTarget, array_map(
            fn (self $s) => $s->normalized(),
            $this->allowedTransitions()
        ), true);
    }

    /**
     * Normalize legacy values to their canonical equivalents.
     */
    public function normalized(): self
    {
        return match ($this) {
            self::READY_TO_DELIVER => self::APPROVED,
            self::DELIVERED => self::PUBLISHED,
            default => $this,
        };
    }

    /**
     * Map to legacy status value for backward compatibility.
     */
    public function toLegacyStatus(): string
    {
        return match ($this) {
            self::IDEA => 'brief', // Map to brief for legacy systems
            self::BRIEF => 'brief',
            self::DRAFT => 'draft',
            self::REVIEW => 'review',
            self::APPROVED, self::READY_TO_DELIVER => 'ready_to_deliver',
            self::SCHEDULED => 'scheduled',
            self::PUBLISHED, self::DELIVERED => 'published',
            self::REFRESH_NEEDED => 'published', // Keep as published in legacy
            self::ARCHIVED => 'archived',
        };
    }

    /**
     * Map legacy content.status values to the new enum.
     */
    public static function fromLegacyStatus(?string $status): self
    {
        return match ($status) {
            'idea' => self::IDEA,
            'brief_received', 'brief' => self::BRIEF,
            'draft', 'generating', 'generated' => self::DRAFT,
            'review' => self::REVIEW,
            'approved', 'ready_to_deliver' => self::APPROVED,
            'scheduled' => self::SCHEDULED,
            'published', 'delivered' => self::PUBLISHED,
            'refresh_needed' => self::REFRESH_NEEDED,
            'archived' => self::ARCHIVED,
            default => self::IDEA,
        };
    }

    /**
     * Get all canonical (non-legacy) stages in order.
     *
     * @return array<self>
     */
    public static function canonicalStages(): array
    {
        return [
            self::IDEA,
            self::BRIEF,
            self::DRAFT,
            self::REVIEW,
            self::APPROVED,
            self::SCHEDULED,
            self::PUBLISHED,
            self::REFRESH_NEEDED,
            self::ARCHIVED,
        ];
    }

    /**
     * Get stages that represent active workflow (not archived).
     *
     * @return array<self>
     */
    public static function activeStages(): array
    {
        return array_filter(
            self::canonicalStages(),
            fn (self $stage) => $stage->isActive()
        );
    }
}
