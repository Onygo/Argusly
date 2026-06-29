<?php

namespace App\Services\Mos\Opportunity;

use App\Enums\OpportunityStatus;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ContentOpportunityCanonicalLifecycleSyncService
{
    public const DIRECTION_LEGACY_TO_CANONICAL = 'legacy-to-canonical';

    public const DIRECTION_CANONICAL_TO_LEGACY = 'canonical-to-legacy';

    public function __construct(
        private readonly ContentOpportunityLifecycleMap $map,
    ) {}

    public function dryRun(
        ContentOpportunity $legacy,
        ?Opportunity $canonical,
        string $direction,
        ?User $actor = null,
    ): ContentOpportunityCanonicalLifecycleSyncResult {
        return $this->sync($legacy, $canonical, $direction, false, $actor);
    }

    public function apply(
        ContentOpportunity $legacy,
        Opportunity $canonical,
        string $direction,
        ?User $actor = null,
    ): ContentOpportunityCanonicalLifecycleSyncResult {
        return $this->sync($legacy, $canonical, $direction, true, $actor);
    }

    public function sync(
        ContentOpportunity $legacy,
        ?Opportunity $canonical,
        string $direction,
        bool $apply = false,
        ?User $actor = null,
    ): ContentOpportunityCanonicalLifecycleSyncResult {
        $direction = $this->normalizeDirection($direction);
        $comparison = $this->map->compare($legacy, $canonical);

        $legacyStatus = $comparison['legacy_status'] ?: null;
        $canonicalStatus = $comparison['canonical_status'] ?: null;
        $desiredLegacyStatus = null;
        $desiredCanonicalStatus = null;
        $blocked = [];

        if (! in_array($direction, $this->directions(), true)) {
            $blocked[] = 'unsupported_direction';
        }

        $duplicateCanonicalLinks = $this->duplicateCanonicalLinks($legacy);

        if (! $canonical) {
            $blocked[] = 'missing_canonical_link';
        } else {
            if ((string) $canonical->content_opportunity_id !== (string) $legacy->id) {
                $blocked[] = 'canonical_legacy_link_mismatch';
            }

            if ((int) $canonical->organization_id !== (int) $legacy->organization_id) {
                $blocked[] = 'organization_mismatch';
            }

            if ((string) $canonical->workspace_id !== (string) $legacy->workspace_id) {
                $blocked[] = 'workspace_mismatch';
            }

            if (filled($canonical->client_site_id) && filled($legacy->client_site_id) && (string) $canonical->client_site_id !== (string) $legacy->client_site_id) {
                $blocked[] = 'client_site_mismatch';
            }
        }

        if ($duplicateCanonicalLinks) {
            $blocked[] = 'duplicate_canonical_links';
        }

        if ($direction === self::DIRECTION_LEGACY_TO_CANONICAL) {
            $desiredCanonicalStatus = $this->map->legacyToCanonical($legacyStatus)?->value;

            if (! $desiredCanonicalStatus) {
                $blocked[] = 'unmapped_legacy_status';
            }
        }

        if ($direction === self::DIRECTION_CANONICAL_TO_LEGACY) {
            $desiredLegacyStatus = $this->map->canonicalToLegacy($canonical?->status);

            if (! $desiredLegacyStatus) {
                $blocked[] = 'blocked_canonical_only_status';
            }
        }

        $blocked = array_values(array_unique($blocked));
        $wouldUpdate = $direction === self::DIRECTION_LEGACY_TO_CANONICAL
            ? $canonical !== null && $desiredCanonicalStatus !== null && $canonicalStatus !== $desiredCanonicalStatus
            : $canonical !== null && $desiredLegacyStatus !== null && $legacyStatus !== $desiredLegacyStatus;
        $safe = $blocked === [];

        if (! $apply || ! $safe || ! $wouldUpdate || ! $canonical) {
            return $this->result(
                legacy: $legacy,
                canonical: $canonical,
                direction: $direction,
                apply: $apply,
                applied: false,
                safe: $safe,
                status: $this->status($safe, $wouldUpdate, $apply),
                comparison: $comparison,
                desiredLegacyStatus: $desiredLegacyStatus,
                desiredCanonicalStatus: $desiredCanonicalStatus,
                duplicateCanonicalLinks: $duplicateCanonicalLinks,
                blocked: $blocked,
                actor: $actor,
            );
        }

        DB::transaction(function () use ($legacy, $canonical, $direction, $desiredLegacyStatus, $desiredCanonicalStatus): void {
            if ($direction === self::DIRECTION_LEGACY_TO_CANONICAL) {
                $canonical->forceFill(['status' => OpportunityStatus::from((string) $desiredCanonicalStatus)])->save();

                return;
            }

            $legacy->forceFill(['status' => $desiredLegacyStatus])->save();
        });

        return $this->result(
            legacy: $legacy->refresh(),
            canonical: $canonical->refresh(),
            direction: $direction,
            apply: true,
            applied: true,
            safe: true,
            status: 'updated',
            comparison: $comparison,
            desiredLegacyStatus: $desiredLegacyStatus,
            desiredCanonicalStatus: $desiredCanonicalStatus,
            duplicateCanonicalLinks: $duplicateCanonicalLinks,
            blocked: [],
            actor: $actor,
        );
    }

    /**
     * @return array<int, string>
     */
    public function directions(): array
    {
        return [
            self::DIRECTION_LEGACY_TO_CANONICAL,
            self::DIRECTION_CANONICAL_TO_LEGACY,
        ];
    }

    private function normalizeDirection(string $direction): string
    {
        return strtolower(trim($direction));
    }

    private function duplicateCanonicalLinks(ContentOpportunity $legacy): bool
    {
        return Opportunity::query()
            ->where('content_opportunity_id', $legacy->id)
            ->limit(2)
            ->count() > 1;
    }

    /**
     * @param  array<string, mixed>  $comparison
     * @param  array<int, string>  $blocked
     */
    private function result(
        ContentOpportunity $legacy,
        ?Opportunity $canonical,
        string $direction,
        bool $apply,
        bool $applied,
        bool $safe,
        string $status,
        array $comparison,
        ?string $desiredLegacyStatus,
        ?string $desiredCanonicalStatus,
        bool $duplicateCanonicalLinks,
        array $blocked,
        ?User $actor,
    ): ContentOpportunityCanonicalLifecycleSyncResult {
        return new ContentOpportunityCanonicalLifecycleSyncResult(
            applied: $applied,
            safe: $safe,
            status: $status,
            direction: $direction,
            legacyContentOpportunityId: (string) $legacy->id,
            canonicalOpportunityId: $canonical?->id ? (string) $canonical->id : null,
            workspaceId: $legacy->workspace_id ? (string) $legacy->workspace_id : null,
            clientSiteId: $legacy->client_site_id ? (string) $legacy->client_site_id : null,
            legacyStatus: $comparison['legacy_status'] ?: null,
            canonicalStatus: $comparison['canonical_status'] ?: null,
            desiredLegacyStatus: $desiredLegacyStatus,
            desiredCanonicalStatus: $desiredCanonicalStatus,
            dryRun: ! $apply,
            aligned: (bool) $comparison['aligned'],
            conflict: (bool) $comparison['conflict'],
            unmappedLegacyStatus: $comparison['unmapped_legacy_status'],
            unmappedCanonicalStatus: $comparison['unmapped_canonical_status'],
            missingCanonicalLink: (bool) $comparison['missing_canonical_link'],
            duplicateCanonicalLinks: $duplicateCanonicalLinks,
            blockedReasons: $blocked,
            actorId: $actor?->id ? (string) $actor->id : null,
        );
    }

    private function status(bool $safe, bool $wouldUpdate, bool $apply): string
    {
        if (! $safe) {
            return 'blocked';
        }

        if (! $wouldUpdate) {
            return 'aligned';
        }

        return $apply ? 'would_update' : 'would_update';
    }
}
