<?php

namespace App\Models;

use App\Enums\ContentLifecycleStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records lifecycle events for content audit trail.
 *
 * Each lifecycle transition, assignment, approval, rejection, or comment
 * is recorded here for workflow tracking and compliance.
 */
class ContentLifecycleEvent extends Model
{
    use HasFactory;
    use HasUuids;

    // Event types
    public const TYPE_TRANSITION = 'transition';
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_REVIEWER_ASSIGNMENT = 'reviewer_assignment';
    public const TYPE_APPROVAL = 'approval';
    public const TYPE_REJECTION = 'rejection';
    public const TYPE_COMMENT = 'comment';
    public const TYPE_DUE_DATE_CHANGE = 'due_date_change';
    public const TYPE_AI_GENERATED = 'ai_generated';
    public const TYPE_OPTIMIZED = 'optimized';
    public const TYPE_PUBLISHED = 'published';
    public const TYPE_AI_VISIBILITY_CHANGED = 'ai_visibility_changed';
    public const TYPE_DECAY_DETECTED = 'decay_detected';
    public const TYPE_REFRESH_GENERATED = 'refresh_generated';
    public const TYPE_REPUBLISHED = 'republished';
    public const TYPE_TRANSLATION_COMPLETED = 'translation_completed';

    // Actor types
    public const ACTOR_USER = 'user';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_AUTOMATION = 'automation';

    protected $fillable = [
        'content_id',
        'from_stage',
        'to_stage',
        'event_type',
        'user_id',
        'actor_type',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // =========================================================================
    // Query Scopes
    // =========================================================================

    public function scopeForContent($query, string $contentId)
    {
        return $query->where('content_id', $contentId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeTransitions($query)
    {
        return $query->where('event_type', self::TYPE_TRANSITION);
    }

    public function scopeApprovals($query)
    {
        return $query->where('event_type', self::TYPE_APPROVAL);
    }

    public function scopeRejections($query)
    {
        return $query->where('event_type', self::TYPE_REJECTION);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    public function isTransition(): bool
    {
        return $this->event_type === self::TYPE_TRANSITION;
    }

    public function isApproval(): bool
    {
        return $this->event_type === self::TYPE_APPROVAL;
    }

    public function isRejection(): bool
    {
        return $this->event_type === self::TYPE_REJECTION;
    }

    public function isUserAction(): bool
    {
        return $this->actor_type === self::ACTOR_USER;
    }

    public function isSystemAction(): bool
    {
        return $this->actor_type === self::ACTOR_SYSTEM;
    }

    public function isAutomationAction(): bool
    {
        return $this->actor_type === self::ACTOR_AUTOMATION;
    }

    /**
     * Get from_stage as enum.
     */
    public function fromStageEnum(): ?ContentLifecycleStatus
    {
        if (! $this->from_stage) {
            return null;
        }

        return ContentLifecycleStatus::tryFrom($this->from_stage);
    }

    /**
     * Get to_stage as enum.
     */
    public function toStageEnum(): ContentLifecycleStatus
    {
        return ContentLifecycleStatus::tryFrom($this->to_stage)
            ?? ContentLifecycleStatus::IDEA;
    }

    // =========================================================================
    // Factory Methods
    // =========================================================================

    /**
     * Record a stage transition event.
     */
    public static function recordTransition(
        Content $content,
        ?ContentLifecycleStatus $fromStage,
        ContentLifecycleStatus $toStage,
        ?User $user = null,
        ?string $notes = null,
        array $metadata = [],
        string $actorType = self::ACTOR_USER
    ): self {
        return self::create([
            'content_id' => $content->id,
            'from_stage' => $fromStage?->value,
            'to_stage' => $toStage->value,
            'event_type' => self::TYPE_TRANSITION,
            'user_id' => $user?->id,
            'actor_type' => $actorType,
            'notes' => $notes,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }

    /**
     * Record an assignment event.
     */
    public static function recordAssignment(
        Content $content,
        User $assignee,
        ?User $assignedBy = null,
        ?string $notes = null,
        array $metadata = []
    ): self {
        $currentStage = $content->lifecycleStageEnum();

        return self::create([
            'content_id' => $content->id,
            'from_stage' => $currentStage->value,
            'to_stage' => $currentStage->value,
            'event_type' => self::TYPE_ASSIGNMENT,
            'user_id' => $assignedBy?->id,
            'actor_type' => $assignedBy ? self::ACTOR_USER : self::ACTOR_SYSTEM,
            'notes' => $notes,
            'metadata' => array_merge($metadata, [
                'assignee_id' => $assignee->id,
                'assignee_name' => $assignee->name,
            ]),
        ]);
    }

    /**
     * Record a reviewer assignment event.
     */
    public static function recordReviewerAssignment(
        Content $content,
        User $reviewer,
        ?User $assignedBy = null,
        ?string $notes = null,
        array $metadata = []
    ): self {
        $currentStage = $content->lifecycleStageEnum();

        return self::create([
            'content_id' => $content->id,
            'from_stage' => $currentStage->value,
            'to_stage' => $currentStage->value,
            'event_type' => self::TYPE_REVIEWER_ASSIGNMENT,
            'user_id' => $assignedBy?->id,
            'actor_type' => $assignedBy ? self::ACTOR_USER : self::ACTOR_SYSTEM,
            'notes' => $notes,
            'metadata' => array_merge($metadata, [
                'reviewer_id' => $reviewer->id,
                'reviewer_name' => $reviewer->name,
            ]),
        ]);
    }

    /**
     * Record an approval event.
     */
    public static function recordApproval(
        Content $content,
        User $approver,
        ?string $notes = null,
        array $metadata = []
    ): self {
        $fromStage = $content->lifecycleStageEnum();

        return self::create([
            'content_id' => $content->id,
            'from_stage' => $fromStage->value,
            'to_stage' => ContentLifecycleStatus::APPROVED->value,
            'event_type' => self::TYPE_APPROVAL,
            'user_id' => $approver->id,
            'actor_type' => self::ACTOR_USER,
            'notes' => $notes,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }

    /**
     * Record a rejection event.
     */
    public static function recordRejection(
        Content $content,
        User $rejector,
        string $reason,
        ?string $notes = null,
        array $metadata = []
    ): self {
        $fromStage = $content->lifecycleStageEnum();

        return self::create([
            'content_id' => $content->id,
            'from_stage' => $fromStage->value,
            'to_stage' => ContentLifecycleStatus::DRAFT->value,
            'event_type' => self::TYPE_REJECTION,
            'user_id' => $rejector->id,
            'actor_type' => self::ACTOR_USER,
            'notes' => $notes,
            'metadata' => array_merge($metadata, [
                'rejection_reason' => $reason,
            ]),
        ]);
    }

    /**
     * Record a comment event.
     */
    public static function recordComment(
        Content $content,
        User $user,
        string $comment,
        array $metadata = []
    ): self {
        $currentStage = $content->lifecycleStageEnum();

        return self::create([
            'content_id' => $content->id,
            'from_stage' => $currentStage->value,
            'to_stage' => $currentStage->value,
            'event_type' => self::TYPE_COMMENT,
            'user_id' => $user->id,
            'actor_type' => self::ACTOR_USER,
            'notes' => $comment,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }

    /**
     * Record a due date change event.
     */
    public static function recordDueDateChange(
        Content $content,
        ?\DateTimeInterface $previousDueDate,
        ?\DateTimeInterface $newDueDate,
        ?User $changedBy = null,
        ?string $notes = null
    ): self {
        $currentStage = $content->lifecycleStageEnum();

        return self::create([
            'content_id' => $content->id,
            'from_stage' => $currentStage->value,
            'to_stage' => $currentStage->value,
            'event_type' => self::TYPE_DUE_DATE_CHANGE,
            'user_id' => $changedBy?->id,
            'actor_type' => $changedBy ? self::ACTOR_USER : self::ACTOR_SYSTEM,
            'notes' => $notes,
            'metadata' => [
                'previous_due_date' => $previousDueDate?->format('Y-m-d H:i:s'),
                'new_due_date' => $newDueDate?->format('Y-m-d H:i:s'),
            ],
        ]);
    }
}
