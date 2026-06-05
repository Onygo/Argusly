<?php

namespace App\Policies;

use App\Enums\ContentLifecycleStatus;
use App\Models\Content;
use App\Models\ClientSite;
use App\Models\User;

class ContentPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, Content $content): bool
    {
        if ($user->is_admin) {
            return true;
        }

        $workspaceOrganizationId = (int) ($content->workspace?->organization_id ?? 0);
        $clientSiteOrganizationId = (int) ($content->clientSite?->workspace?->organization_id ?? 0);

        $belongsToOrganization =
            $workspaceOrganizationId === (int) $user->organization_id ||
            $clientSiteOrganizationId === (int) $user->organization_id;

        return $belongsToOrganization
            && in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, Content $content): bool
    {
        if (! $this->view($user, $content)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function generateDraft(User $user, Content $content): bool
    {
        return $this->update($user, $content);
    }

    public function generateImage(User $user, Content $content): bool
    {
        return $this->update($user, $content);
    }

    public function pushFeaturedImage(User $user, Content $content): bool
    {
        if (! $this->update($user, $content)) {
            return false;
        }

        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));

        return in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true);
    }

    public function pushToWp(User $user, Content $content): bool
    {
        return $this->pushFeaturedImage($user, $content);
    }

    public function restoreRevision(User $user, Content $content): bool
    {
        return $this->update($user, $content);
    }

    public function delete(User $user, Content $content): bool
    {
        return $this->update($user, $content);
    }

    public function restore(User $user, Content $content): bool
    {
        return $this->update($user, $content);
    }

    public function runAgent(User $user, Content $content): bool
    {
        return $this->update($user, $content);
    }

    // =========================================================================
    // Lifecycle Management Policies
    // =========================================================================

    /**
     * Determine if user can transition content to a specific stage.
     *
     * Admins/owners can override any transition.
     * Editors can move content through most stages.
     * Reviewers can only approve/reject content they're assigned to review.
     */
    public function transition(User $user, Content $content, string $targetStage): bool
    {
        // Must have basic view access
        if (! $this->view($user, $content)) {
            return false;
        }

        // Superadmins can do anything
        if ($user->is_admin) {
            return true;
        }

        // Owners and admins can override any transition
        if (in_array((string) $user->role, ['owner', 'admin'], true)) {
            return true;
        }

        $target = ContentLifecycleStatus::tryFrom($targetStage);
        if (! $target) {
            return false;
        }

        // Check if the transition itself is valid
        if (! $content->canTransitionTo($target)) {
            return false;
        }

        // Editors can perform most transitions
        if ((string) $user->role === 'editor') {
            return true;
        }

        // Reviewers can only approve/reject content they're assigned to review
        if ((string) $user->role === 'reviewer') {
            // Must be the assigned reviewer
            if (! $content->isReviewerFor($user)) {
                return false;
            }

            // Can only transition to approved or draft (rejection)
            return in_array($target, [
                ContentLifecycleStatus::APPROVED,
                ContentLifecycleStatus::DRAFT,
            ], true);
        }

        return false;
    }

    /**
     * Determine if user can approve content.
     *
     * Allowed for: superadmin, owner, admin, editor, or assigned reviewer.
     */
    public function approve(User $user, Content $content): bool
    {
        if (! $this->view($user, $content)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        // Owners, admins, and editors can approve
        if (in_array((string) $user->role, ['owner', 'admin', 'editor'], true)) {
            return true;
        }

        // Reviewers can approve if they're the assigned reviewer
        if ((string) $user->role === 'reviewer' && $content->isReviewerFor($user)) {
            return true;
        }

        return false;
    }

    /**
     * Determine if user can reject content.
     *
     * Same rules as approve - reviewer, admin, or owner.
     */
    public function reject(User $user, Content $content): bool
    {
        return $this->approve($user, $content);
    }

    /**
     * Determine if user can assign content to another user.
     *
     * Allowed for: superadmin, owner, admin, editor.
     */
    public function assign(User $user, Content $content): bool
    {
        if (! $this->view($user, $content)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    /**
     * Determine if user can set a reviewer for content.
     *
     * Same rules as assign.
     */
    public function setReviewer(User $user, Content $content): bool
    {
        return $this->assign($user, $content);
    }

    /**
     * Determine if user can send content to review.
     *
     * Must have update permission and content must be in a valid stage.
     */
    public function sendToReview(User $user, Content $content): bool
    {
        if (! $this->update($user, $content)) {
            return false;
        }

        // Content must be in a stage that allows transition to review
        return $content->canTransitionTo(ContentLifecycleStatus::REVIEW);
    }

    /**
     * Determine if user can mark content as needing refresh.
     *
     * Allowed for: superadmin, owner, admin, editor.
     */
    public function markRefreshNeeded(User $user, Content $content): bool
    {
        if (! $this->view($user, $content)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    /**
     * Determine if user can view lifecycle history for content.
     *
     * Anyone who can view the content can see its history.
     */
    public function viewLifecycleHistory(User $user, Content $content): bool
    {
        return $this->view($user, $content);
    }

    /**
     * Determine if user can archive content.
     *
     * Allowed for: superadmin, owner, admin, editor.
     */
    public function archive(User $user, Content $content): bool
    {
        if (! $this->view($user, $content)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }
}
