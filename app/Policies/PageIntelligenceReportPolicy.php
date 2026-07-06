<?php

namespace App\Policies;

use App\Models\PageIntelligenceReport;
use App\Models\User;
use App\Policies\Concerns\AuthorizesSignalIntelligence;

class PageIntelligenceReportPolicy
{
    use AuthorizesSignalIntelligence;

    public function view(User $user, PageIntelligenceReport $report): bool
    {
        return $this->hasAccess($user) && $this->belongsToOrganization($user, $report);
    }

    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    public function update(User $user, PageIntelligenceReport $report): bool
    {
        return $this->view($user, $report) && $this->canManage($user);
    }

    public function generateArtifact(User $user, PageIntelligenceReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function download(User $user, PageIntelligenceReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function delete(User $user, PageIntelligenceReport $report): bool
    {
        return $this->update($user, $report);
    }
}
