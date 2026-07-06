<?php

namespace App\Policies;

use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\User;

class ContentSeriesPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor', 'reviewer', 'viewer', 'member'], true);
    }

    public function view(User $user, ContentSeries $series): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return (int) $user->organization_id === (int) $series->organization_id;
    }

    public function create(User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return in_array((string) $user->role, ['owner', 'admin', 'editor'], true);
    }

    public function update(User $user, ContentSeries $series): bool
    {
        if (! $this->view($user, $series)) {
            return false;
        }

        if (! $this->create($user)) {
            return false;
        }

        return ! $series->isLocked() && ! $series->isArchived();
    }

    public function publish(User $user, ContentSeries $series): bool
    {
        if (! $this->view($user, $series)) {
            return false;
        }

        if (! $this->create($user)) {
            return false;
        }

        return ! $series->isArchived()
            && (! $series->isLocked() || $this->lockedSeriesHasUnpublishedTranslations($series));
    }

    public function duplicate(User $user, ContentSeries $series): bool
    {
        return $this->view($user, $series) && $this->create($user);
    }

    public function archive(User $user, ContentSeries $series): bool
    {
        if (! $this->view($user, $series)) {
            return false;
        }

        if (! $this->create($user)) {
            return false;
        }

        return $series->normalizedStatus() === ContentSeries::STATUS_PUBLISHED;
    }

    public function delete(User $user, ContentSeries $series): bool
    {
        if (! $this->view($user, $series)) {
            return false;
        }

        if (! $this->create($user)) {
            return false;
        }

        return $series->normalizedStatus() === ContentSeries::STATUS_DRAFT && ! $series->isLocked();
    }

    private function lockedSeriesHasUnpublishedTranslations(ContentSeries $series): bool
    {
        if (! $series->isLocked() || ! $series->isPublished()) {
            return false;
        }

        $sourceIds = $series->contents()
            ->pluck('contents.id')
            ->map(fn ($id): string => (string) $id)
            ->filter()
            ->values();

        if ($sourceIds->isEmpty()) {
            return false;
        }

        return Content::query()
            ->where(function ($query) use ($sourceIds): void {
                $query->whereIn('translation_source_content_id', $sourceIds)
                    ->orWhereIn('family_id', $sourceIds);
            })
            ->where(function ($query): void {
                $query->whereNull('is_source_locale')
                    ->orWhere('is_source_locale', false);
            })
            ->where(function ($query): void {
                $query->whereNull('publish_status')
                    ->orWhere('publish_status', '!=', 'published');
            })
            ->exists();
    }
}
