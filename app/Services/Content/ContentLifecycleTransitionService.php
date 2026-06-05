<?php

namespace App\Services\Content;

use App\Enums\ContentLifecycleStatus;
use App\Exceptions\InvalidLifecycleTransitionException;
use App\Models\Content;
use App\Models\ContentLifecycleEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Service for managing content lifecycle transitions.
 *
 * Handles stage transitions, approvals, rejections, assignments,
 * and maintains audit trail via ContentLifecycleEvent records.
 */
class ContentLifecycleTransitionService
{
    /**
     * Transition content to a new lifecycle stage.
     *
     * @throws InvalidLifecycleTransitionException
     */
    public function transition(
        Content $content,
        ContentLifecycleStatus $targetStage,
        ?User $user = null,
        ?string $notes = null,
        string $actorType = ContentLifecycleEvent::ACTOR_USER
    ): Content {
        $fromStage = $content->lifecycleStageEnum();

        // Same stage is a no-op
        if ($fromStage->normalized() === $targetStage->normalized()) {
            return $content;
        }

        // Validate transition
        if (! $fromStage->canTransitionTo($targetStage)) {
            throw InvalidLifecycleTransitionException::invalidTransition($content, $fromStage, $targetStage);
        }

        return DB::transaction(function () use ($content, $fromStage, $targetStage, $user, $notes, $actorType) {
            // Update content
            $content->lifecycle_stage = $targetStage;
            $content->status = $targetStage->toLegacyStatus();

            // Clear rejection fields on forward progress (unless going to archived)
            if ($targetStage !== ContentLifecycleStatus::ARCHIVED
                && ! in_array($targetStage, [ContentLifecycleStatus::DRAFT, ContentLifecycleStatus::REVIEW], true)
            ) {
                $content->rejected_at = null;
                $content->rejected_by = null;
                $content->rejection_reason = null;
            }

            $content->save();

            // Record audit event
            ContentLifecycleEvent::recordTransition(
                $content,
                $fromStage,
                $targetStage,
                $user,
                $notes,
                [],
                $actorType
            );

            return $content->fresh();
        });
    }

    /**
     * Send content to review stage, optionally setting a reviewer.
     */
    public function sendToReview(
        Content $content,
        User $user,
        ?User $reviewer = null,
        ?string $notes = null
    ): Content {
        $content = $this->transition($content, ContentLifecycleStatus::REVIEW, $user, $notes);

        if ($reviewer) {
            $this->setReviewer($content, $reviewer, $user);
        }

        return $content->fresh();
    }

    /**
     * Approve content and transition to approved stage.
     */
    public function approve(
        Content $content,
        User $approver,
        ?string $notes = null
    ): Content {
        $fromStage = $content->lifecycleStageEnum();

        return DB::transaction(function () use ($content, $fromStage, $approver, $notes) {
            // Update content
            $content->lifecycle_stage = ContentLifecycleStatus::APPROVED;
            $content->status = ContentLifecycleStatus::APPROVED->toLegacyStatus();
            $content->approved_at = now();
            $content->approved_by = $approver->id;

            // Clear rejection fields
            $content->rejected_at = null;
            $content->rejected_by = null;
            $content->rejection_reason = null;

            $content->save();

            // Record audit event
            ContentLifecycleEvent::recordApproval($content, $approver, $notes);

            return $content->fresh();
        });
    }

    /**
     * Reject content and send back to draft stage.
     */
    public function reject(
        Content $content,
        User $rejector,
        string $reason,
        ?string $notes = null
    ): Content {
        $fromStage = $content->lifecycleStageEnum();

        return DB::transaction(function () use ($content, $fromStage, $rejector, $reason, $notes) {
            // Update content
            $content->lifecycle_stage = ContentLifecycleStatus::DRAFT;
            $content->status = ContentLifecycleStatus::DRAFT->toLegacyStatus();
            $content->rejected_at = now();
            $content->rejected_by = $rejector->id;
            $content->rejection_reason = $reason;

            // Clear approval fields
            $content->approved_at = null;
            $content->approved_by = null;

            $content->save();

            // Record audit event
            ContentLifecycleEvent::recordRejection($content, $rejector, $reason, $notes);

            return $content->fresh();
        });
    }

    /**
     * Assign content to a user.
     */
    public function assign(
        Content $content,
        User $assignee,
        ?User $assignedBy = null,
        ?string $notes = null
    ): Content {
        return DB::transaction(function () use ($content, $assignee, $assignedBy, $notes) {
            $content->assigned_user_id = $assignee->id;
            $content->save();

            ContentLifecycleEvent::recordAssignment($content, $assignee, $assignedBy, $notes);

            return $content->fresh();
        });
    }

    /**
     * Set reviewer for content.
     */
    public function setReviewer(
        Content $content,
        User $reviewer,
        ?User $setBy = null,
        ?string $notes = null
    ): Content {
        return DB::transaction(function () use ($content, $reviewer, $setBy, $notes) {
            $content->reviewer_user_id = $reviewer->id;
            $content->save();

            ContentLifecycleEvent::recordReviewerAssignment($content, $reviewer, $setBy, $notes);

            return $content->fresh();
        });
    }

    /**
     * Mark content as needing refresh.
     */
    public function markRefreshNeeded(
        Content $content,
        ?User $user = null,
        ?string $reason = null
    ): Content {
        return $this->transition(
            $content,
            ContentLifecycleStatus::REFRESH_NEEDED,
            $user,
            $reason,
            $user ? ContentLifecycleEvent::ACTOR_USER : ContentLifecycleEvent::ACTOR_SYSTEM
        );
    }

    /**
     * Archive content.
     */
    public function archive(
        Content $content,
        ?User $user = null,
        ?string $reason = null
    ): Content {
        return $this->transition($content, ContentLifecycleStatus::ARCHIVED, $user, $reason);
    }

    /**
     * Update due date for content.
     */
    public function setDueDate(
        Content $content,
        ?\DateTimeInterface $dueDate,
        ?User $changedBy = null,
        ?string $notes = null
    ): Content {
        return DB::transaction(function () use ($content, $dueDate, $changedBy, $notes) {
            $previousDueDate = $content->due_at;

            $content->due_at = $dueDate;
            $content->save();

            ContentLifecycleEvent::recordDueDateChange(
                $content,
                $previousDueDate,
                $dueDate,
                $changedBy,
                $notes
            );

            return $content->fresh();
        });
    }

    /**
     * Check if a transition is valid.
     */
    public function canTransition(
        Content $content,
        ContentLifecycleStatus $targetStage,
        ?User $user = null
    ): bool {
        $currentStage = $content->lifecycleStageEnum();

        // Same stage is always valid
        if ($currentStage->normalized() === $targetStage->normalized()) {
            return true;
        }

        return $currentStage->canTransitionTo($targetStage);
    }

    /**
     * Get allowed transitions for a content item, optionally filtered by user permissions.
     *
     * @return array<ContentLifecycleStatus>
     */
    public function getAllowedTransitions(Content $content, ?User $user = null): array
    {
        $currentStage = $content->lifecycleStageEnum();

        return $currentStage->allowedTransitions();
    }

    /**
     * Check if user can transition content to a specific stage.
     */
    public function userCanTransitionTo(
        Content $content,
        ContentLifecycleStatus $targetStage,
        User $user
    ): bool {
        // First check if the transition is valid
        if (! $this->canTransition($content, $targetStage)) {
            return false;
        }

        // Admins and superadmins can do anything
        if ($user->is_admin || in_array($user->role, ['owner', 'admin'], true)) {
            return true;
        }

        // For approval transitions, check if user is reviewer or has editor role
        if ($targetStage === ContentLifecycleStatus::APPROVED) {
            return $content->isReviewerFor($user)
                || in_array($user->role, ['editor'], true);
        }

        // For most transitions, editors can proceed
        if (in_array($user->role, ['owner', 'admin', 'editor'], true)) {
            return true;
        }

        // Reviewers can only approve/reject when assigned as reviewer
        if ($user->role === 'reviewer') {
            return $content->isReviewerFor($user)
                && in_array($targetStage, [ContentLifecycleStatus::APPROVED, ContentLifecycleStatus::DRAFT], true);
        }

        return false;
    }

    /**
     * Transition content to published stage (typically called after successful delivery).
     */
    public function markPublished(
        Content $content,
        ?User $user = null,
        ?string $notes = null
    ): Content {
        return $this->transition(
            $content,
            ContentLifecycleStatus::PUBLISHED,
            $user,
            $notes,
            $user ? ContentLifecycleEvent::ACTOR_USER : ContentLifecycleEvent::ACTOR_SYSTEM
        );
    }

    /**
     * Transition content to scheduled stage.
     */
    public function markScheduled(
        Content $content,
        ?User $user = null,
        ?string $notes = null
    ): Content {
        return $this->transition(
            $content,
            ContentLifecycleStatus::SCHEDULED,
            $user,
            $notes,
            $user ? ContentLifecycleEvent::ACTOR_USER : ContentLifecycleEvent::ACTOR_SYSTEM
        );
    }
}
