<?php

namespace App\Domain\AccessOverrides;

use App\Enums\AccessOverrideStatus;
use App\Models\AccessOverride;
use App\Models\User;
use Illuminate\Support\CarbonInterface;

class AccessOverrideResolver
{
    public function hasActiveOverrideForUser(User $user, ?CarbonInterface $now = null): bool
    {
        return $this->getActiveOverrideForUser($user, $now) !== null;
    }

    public function getActiveOverrideForUser(User $user, ?CarbonInterface $now = null): ?AccessOverride
    {
        $now = $now ?: now();

        return AccessOverride::query()
            ->forUser($user)
            ->open()
            ->where(function ($query) use ($now): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', $now);
            })
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'scheduled' THEN 1 ELSE 9 END")
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function getLatestOverrideForUser(User $user): ?AccessOverride
    {
        return AccessOverride::query()
            ->forUser($user)
            ->latest('created_at')
            ->first();
    }

    public function getOpenOverrideForUser(User $user): ?AccessOverride
    {
        return AccessOverride::query()
            ->forUser($user)
            ->open()
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'scheduled' THEN 1 ELSE 9 END")
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->first();
    }

    public function allowsBillingBypass(User $user, ?CarbonInterface $now = null): bool
    {
        return $this->hasActiveOverrideForUser($user, $now);
    }

    public function effectiveStatus(AccessOverride $override, ?CarbonInterface $now = null): AccessOverrideStatus
    {
        return $override->effectiveStatus($now);
    }

    public function isExpired(AccessOverride $override, ?CarbonInterface $now = null): bool
    {
        return $this->effectiveStatus($override, $now) === AccessOverrideStatus::EXPIRED;
    }

    public function uiMessageForOverride(AccessOverride $override, ?CarbonInterface $now = null): string
    {
        return $override->uiMessage($now);
    }

    public function expireDueOverrides(?CarbonInterface $now = null): int
    {
        $now = $now ?: now();
        $expired = 0;

        AccessOverride::query()
            ->open()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->orderBy('ends_at')
            ->chunkById(100, function ($overrides) use (&$expired, $now): void {
                foreach ($overrides as $override) {
                    $override->status = AccessOverrideStatus::EXPIRED;
                    $override->ended_at = $override->ended_at ?? $override->ends_at ?? $now;
                    $override->save();
                    $expired++;
                }
            }, 'id');

        return $expired;
    }
}
