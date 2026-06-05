<?php

namespace App\Services\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;

class SupportContext
{
    private bool $enabled = false;

    private ?Organization $targetCompany = null;

    private ?User $targetUser = null;

    private ?User $startedBy = null;

    private ?string $reason = null;

    private ?string $startedAt = null;

    public function hydrateFromRequest(Request $request): void
    {
        if (! $request->hasSession()) {
            $this->clearInMemory();

            return;
        }

        $this->enabled = (bool) $request->session()->get('support_mode_enabled', false);
        if (! $this->enabled) {
            $this->clearInMemory();
            return;
        }

        $companyId = (int) $request->session()->get('support_target_company_id');
        $targetUserId = (int) $request->session()->get('support_target_user_id');
        $startedByAdminId = (int) $request->session()->get('support_started_by_admin_id');

        $this->targetCompany = $companyId > 0 ? Organization::query()->find($companyId) : null;
        $this->targetUser = $targetUserId > 0 ? User::query()->find($targetUserId) : null;
        $this->startedBy = $startedByAdminId > 0 ? User::query()->find($startedByAdminId) : null;
        $this->reason = trim((string) $request->session()->get('support_reason', '')) ?: null;
        $this->startedAt = (string) $request->session()->get('support_started_at', '');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function targetCompany(): ?Organization
    {
        return $this->targetCompany;
    }

    public function targetUser(): ?User
    {
        return $this->targetUser;
    }

    public function startedBy(): ?User
    {
        return $this->startedBy;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function startedAt(): ?string
    {
        return $this->startedAt ?: null;
    }

    public function clear(Request $request): void
    {
        $request->session()->forget([
            'support_mode_enabled',
            'support_target_company_id',
            'support_target_user_id',
            'support_started_by_admin_id',
            'support_started_at',
            'support_reason',
        ]);

        $this->clearInMemory();
    }

    private function clearInMemory(): void
    {
        $this->enabled = false;
        $this->targetCompany = null;
        $this->targetUser = null;
        $this->startedBy = null;
        $this->reason = null;
        $this->startedAt = null;
    }
}
