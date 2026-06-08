<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentCreditLog;
use App\Models\ContentImage;
use App\Models\CreditAction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\CreditWallet;
use App\Models\Draft;
use App\Models\SiteCreditAllocation;
use App\Models\SiteCreditAllocationBucket;
use App\Models\WorkspaceCreditTransaction;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use App\Services\Credits\SiteCreditAllocationService;
use App\Services\Credits\WorkspaceCreditLedgerService;

class CreditWalletService
{
    public const TYPE_ALLOWANCE = 'allowance';

    public const TYPE_PACK_PURCHASE = 'pack_purchase';

    public const TYPE_RESERVATION = 'reservation';

    public const TYPE_RELEASE = 'release';

    public const TYPE_USAGE = 'usage';

    public const TYPE_REFUND = 'refund';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly WorkspaceCreditLedgerService $workspaceCredits,
        private readonly SiteCreditAllocationService $siteAllocations
    ) {}

    public function getOrCreateWalletForClientSite(string $clientSiteId): CreditWallet
    {
        $workspaceId = ClientSite::query()->where('id', $clientSiteId)->value('workspace_id');

        return CreditWallet::query()->firstOrCreate(
            ['client_site_id' => $clientSiteId],
            ['workspace_id' => $workspaceId, 'balance_cached' => 0, 'reserved_cached' => 0]
        );
    }

    public function getAvailableForClientSite(string $clientSiteId): int
    {
        $allocation = SiteCreditAllocation::query()->where('client_site_id', $clientSiteId)->first();
        if ($allocation) {
            return max(0, (int) $allocation->remaining);
        }

        $wallet = $this->getOrCreateWalletForClientSite($clientSiteId);

        return (int) $wallet->available;
    }

    public function getAvailableForClientSiteIncludingWorkspacePool(string $clientSiteId): int
    {
        $siteAvailable = $this->getAvailableForClientSite($clientSiteId);
        $workspaceId = (string) ClientSite::query()->whereKey($clientSiteId)->value('workspace_id');

        if ($workspaceId === '') {
            return $siteAvailable;
        }

        return $siteAvailable + $this->getUnallocatedWorkspaceCredits($workspaceId);
    }

    public function ensureAvailableForClientSite(string $clientSiteId, int $requiredCredits, ?int $userId = null, array $metadata = []): int
    {
        $requiredCredits = max(0, $requiredCredits);
        $siteAvailable = $this->getAvailableForClientSite($clientSiteId);

        if ($requiredCredits === 0 || $siteAvailable >= $requiredCredits) {
            return $siteAvailable;
        }

        $site = ClientSite::query()->find($clientSiteId);
        $workspaceId = (string) ($site?->workspace_id ?? '');
        if ($workspaceId === '') {
            return $siteAvailable;
        }

        $deficit = $requiredCredits - $siteAvailable;
        $workspaceAvailable = $this->getUnallocatedWorkspaceCredits($workspaceId);
        if ($workspaceAvailable <= 0) {
            return $siteAvailable;
        }

        $this->siteAllocations->allocateToSite(
            clientSiteId: $clientSiteId,
            amount: min($deficit, $workspaceAvailable),
            userId: $userId,
            metadata: array_merge($metadata, [
                'event' => 'auto_allocate_for_generation',
                'required_credits' => $requiredCredits,
                'site_available_before' => $siteAvailable,
            ])
        );

        return $this->getAvailableForClientSite($clientSiteId);
    }

    public function addWorkspaceCredits(
        string $workspaceId,
        int $amount,
        string $type,
        array $meta = [],
        ?string $sourceType = null,
        ?string $sourceId = null,
        $expiresAt = null,
        ?string $idempotencyKey = null,
        ?string $preferredClientSiteId = null
    ): ?CreditLedgerEntry {
        if (trim($workspaceId) === '' && $preferredClientSiteId) {
            $workspaceId = (string) ClientSite::query()->whereKey($preferredClientSiteId)->value('workspace_id');
        }

        if (trim($workspaceId) === '') {
            throw new RuntimeException('Workspace is required for workspace credit grants.');
        }

        if ($idempotencyKey) {
            $existingWorkspaceTransaction = \App\Models\WorkspaceCreditTransaction::query()
                ->where('idempotency_key', 'workspace:' . $idempotencyKey)
                ->first();

            if ($existingWorkspaceTransaction) {
                return $preferredClientSiteId && $idempotencyKey
                    ? CreditLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first()
                    : null;
            }
        }

        $site = null;
        if ($preferredClientSiteId) {
            $site = ClientSite::query()->with('workspace')->find($preferredClientSiteId);
        }

        $organizationId = $site?->workspace?->organization_id
            ?? ClientSite::query()->where('workspace_id', $workspaceId)
                ->join('workspaces', 'client_sites.workspace_id', '=', 'workspaces.id')
                ->value('workspaces.organization_id');

        $source = $this->resolveSourceFromType($type);
        $workspaceType = match ($type) {
            self::TYPE_ALLOWANCE => WorkspaceCreditLedgerService::TYPE_SUBSCRIPTION_GRANT,
            self::TYPE_PACK_PURCHASE => WorkspaceCreditLedgerService::TYPE_PURCHASE,
            self::TYPE_REFUND => WorkspaceCreditLedgerService::TYPE_REFUND,
            default => WorkspaceCreditLedgerService::TYPE_ADJUSTMENT,
        };

        $this->workspaceCredits->addCreditsToWorkspace(
            workspaceId: $workspaceId,
            amount: $amount,
            type: $workspaceType,
            organizationId: $organizationId ? (int) $organizationId : null,
            source: $source,
            metadata: array_merge($meta, [
                'credit_type' => $type,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]),
            referenceType: $sourceType,
            referenceId: $sourceId,
            expiresAt: $expiresAt,
            idempotencyKey: $idempotencyKey ? 'workspace:' . $idempotencyKey : null
        );

        $autoAllocateSiteId = $preferredClientSiteId
            ?: $this->workspaceCredits->autoAllocatePreferredSite($workspaceId);

        if ($autoAllocateSiteId) {
            $expiresAtCarbon = $expiresAt ? \Illuminate\Support\Carbon::parse($expiresAt) : null;

            $this->siteAllocations->allocateToSite($autoAllocateSiteId, $amount, null, [
                'auto_allocated' => true,
                'allow_expired_source_buckets' => $expiresAtCarbon?->lte(now()) ?? false,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'credit_type' => $type,
                'workspace_grant_idempotency_key' => $idempotencyKey,
                'legacy_entry_idempotency_key' => $idempotencyKey,
                ...$meta,
            ]);

            if ($idempotencyKey) {
                return CreditLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
            }

            return CreditLedgerEntry::query()
                ->where('client_site_id', $autoAllocateSiteId)
                ->where('type', $type)
                ->when($sourceType, fn ($query) => $query->where('source_type', $sourceType))
                ->when($sourceId, fn ($query) => $query->where('source_id', $sourceId))
                ->latest('created_at')
                ->first();
        }

        return null;
    }

    public function consumeForContentImage(string $clientSiteId, string $contentId, int $amount, ?string $userId = null): CreditLedgerEntry
    {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be positive.');
        }

        return DB::transaction(function () use ($clientSiteId, $contentId, $amount, $userId): CreditLedgerEntry {
            $wallet = CreditWallet::query()
                ->where('client_site_id', $clientSiteId)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw new RuntimeException('Credit wallet not found for connected site.');
            }

            $available = $this->getConsumableCreditsForWallet((string) $wallet->id) - (int) $wallet->reserved_cached;
            if ($available < $amount) {
                throw new InsufficientCreditsException($amount, max(0, $available));
            }

            $allocations = $this->consumeFromBuckets($wallet, $amount);
            $organizationId = $this->resolveOrganizationIdByClientSite($clientSiteId);

            $usage = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_USAGE,
                'source' => 'usage',
                'amount' => -$amount,
                'remaining' => 0,
                'source_type' => Content::class,
                'source_id' => $contentId,
                'client_site_id' => $clientSiteId,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'meta' => [
                    'event' => 'content.featured_image.generate',
                    'allocations' => $allocations,
                    'consumption_policy' => 'included_first_then_addon',
                ],
            ]);

            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->balance_cached -= $amount;
                $wallet->save();
            }

            ContentCreditLog::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => $contentId,
                'draft_id' => null,
                'credit_ledger_entry_id' => $usage->id,
                'event' => 'rewrite',
                'credits_used' => $amount,
                'mode_multiplier' => 1.0,
                'meta' => [
                    'event_type' => 'image_generation',
                ],
            ]);

            return $usage;
        });
    }

    public function reserveForContentImage(ContentImage $image, ?string $userId = null): CreditLedgerEntry
    {
        $content = $image->content()->with('clientSite.workspace')->first();
        if (! $content || ! $content->client_site_id) {
            throw new RuntimeException('Image has no connected site for reservation.');
        }

        $cost = max(1, (int) ($image->credit_cost ?: config('argusly.ai.images.credit_cost', 6)));

        if ($image->credit_status === 'reserved' && $image->credit_ledger_entry_id) {
            $existingReservation = CreditLedgerEntry::query()->find($image->credit_ledger_entry_id);
            if ($existingReservation) {
                return $existingReservation;
            }
        }

        if (in_array((string) $image->credit_status, ['committed', 'released'], true) && $image->credit_ledger_entry_id) {
            $existing = CreditLedgerEntry::query()->find($image->credit_ledger_entry_id);
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($image, $content, $cost, $userId): CreditLedgerEntry {
            $freshImage = ContentImage::query()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            $idempotencyKey = sprintf('content_image:%s:reserve', (string) $freshImage->id);

            $existingReservation = CreditLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingReservation) {
                if ((string) $freshImage->credit_status !== 'reserved') {
                    $freshImage->credit_wallet_id = $existingReservation->credit_wallet_id;
                    $freshImage->credit_status = 'reserved';
                    $freshImage->credit_ledger_entry_id = $existingReservation->id;
                    $freshImage->credit_cost = $cost;
                    $freshImage->save();
                }

                return $existingReservation;
            }

            $wallet = CreditWallet::query()
                ->where('client_site_id', $content->client_site_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = CreditWallet::create([
                    'id' => (string) Str::uuid(),
                    'client_site_id' => $content->client_site_id,
                    'workspace_id' => ClientSite::query()->where('id', $content->client_site_id)->value('workspace_id'),
                    'balance_cached' => 0,
                    'reserved_cached' => 0,
                ]);
                $wallet->refresh();
            }

            $consumable = $this->getConsumableCreditsForWallet((string) $wallet->id);
            $available = $consumable - (int) $wallet->reserved_cached;
            if ($available < $cost) {
                throw new InsufficientCreditsException($cost, max(0, $available));
            }

            $organizationId = $this->resolveOrganizationIdByClientSite((string) $content->client_site_id);

            $reservation = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RESERVATION,
                'source' => 'usage',
                'amount' => $cost,
                'remaining' => 0,
                'source_type' => ContentImage::class,
                'source_id' => $freshImage->id,
                'client_site_id' => $content->client_site_id,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'meta' => [
                    'event' => 'content.featured_image.generate',
                    'content_id' => (string) $freshImage->content_id,
                    'image_type' => (string) $freshImage->type,
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached += $cost;
                $wallet->save();
            }

            $this->siteAllocations->reserve((string) $content->client_site_id, $cost);
            $this->workspaceCredits->adjustReserved((string) $wallet->workspace_id, $cost);
            $workspaceReservation = $this->workspaceCredits->recordReservation(
                workspaceId: (string) $wallet->workspace_id,
                amount: $cost,
                clientSiteId: (string) $content->client_site_id,
                allocationId: $this->resolveSiteAllocationId((string) $content->client_site_id),
                metadata: [
                    'feature' => 'content_image.generate',
                    'content_id' => (string) $freshImage->content_id,
                    'image_type' => (string) $freshImage->type,
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            $freshImage->credit_wallet_id = $wallet->id;
            $freshImage->workspace_credit_wallet_id = $workspaceReservation->workspace_credit_wallet_id;
            $freshImage->credit_status = 'reserved';
            $freshImage->credit_ledger_entry_id = $reservation->id;
            $freshImage->workspace_credit_transaction_id = $workspaceReservation->id;
            $freshImage->credit_cost = $cost;
            $freshImage->credit_release_reason = null;
            $freshImage->save();

            // Create CreditReservation record for tracking and admin management
            $this->createReservationRecord(
                wallet: $wallet,
                ledgerEntry: $reservation,
                context: $freshImage,
                purpose: 'image_generate',
                userId: $userId,
                organizationId: $organizationId,
                reservationWorkspaceTransactionId: $workspaceReservation->id
            );

            return $reservation;
        });
    }

    public function commitUsageForContentImage(ContentImage $image, ?string $userId = null): CreditLedgerEntry
    {
        $cost = max(1, (int) ($image->credit_cost ?: config('argusly.ai.images.credit_cost', 6)));

        if ($image->credit_status === 'committed' && $image->credit_ledger_entry_id) {
            $existingUsage = CreditLedgerEntry::query()->find($image->credit_ledger_entry_id);
            if ($existingUsage) {
                return $existingUsage;
            }
        }

        if ($image->credit_status === 'released') {
            throw new RuntimeException('Cannot capture image credits: reservation was already released.');
        }

        if ($image->credit_status !== 'reserved') {
            throw new RuntimeException('Image is not in reserved status.');
        }

        return DB::transaction(function () use ($image, $cost, $userId): CreditLedgerEntry {
            $freshImage = ContentImage::query()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            $content = Content::query()->findOrFail($freshImage->content_id);

            $wallet = CreditWallet::query()
                ->whereKey($freshImage->credit_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $reservationEntryId = (string) ($freshImage->credit_ledger_entry_id ?? '');
            $usageIdempotencyKey = sprintf(
                'content_image:%s:usage:%s',
                (string) $freshImage->id,
                $reservationEntryId !== '' ? $reservationEntryId : 'none'
            );
            $existingUsage = CreditLedgerEntry::query()->where('idempotency_key', $usageIdempotencyKey)->first();
            if ($existingUsage) {
                if ((string) $freshImage->credit_status !== 'committed') {
                    $freshImage->credit_status = 'committed';
                    $freshImage->credit_ledger_entry_id = $existingUsage->id;
                    $freshImage->credit_release_reason = null;
                    $freshImage->save();
                }

                return $existingUsage;
            }

            $release = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => ContentImage::class,
                'source_id' => $freshImage->id,
                'client_site_id' => $content->client_site_id,
                'organization_id' => $this->resolveOrganizationIdByClientSite((string) $content->client_site_id),
                'user_id' => $userId,
                'meta' => [
                    'reservation_entry_id' => $freshImage->credit_ledger_entry_id,
                    'reason' => 'capture',
                ],
                'idempotency_key' => sprintf(
                    'content_image:%s:release-for-commit:%s',
                    (string) $freshImage->id,
                    $reservationEntryId !== '' ? $reservationEntryId : 'none'
                ),
            ]);

            $allocations = $this->consumeFromBuckets($wallet, $cost);

            $usage = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_USAGE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => ContentImage::class,
                'source_id' => $freshImage->id,
                'client_site_id' => $content->client_site_id,
                'organization_id' => $this->resolveOrganizationIdByClientSite((string) $content->client_site_id),
                'user_id' => $userId,
                'meta' => [
                    'event' => 'content.featured_image.generate',
                    'content_id' => (string) $freshImage->content_id,
                    'reservation_entry_id' => $freshImage->credit_ledger_entry_id,
                    'release_entry_id' => $release->id,
                    'allocations' => $allocations,
                    'consumption_policy' => 'included_first_then_addon',
                ],
                'idempotency_key' => $usageIdempotencyKey,
            ]);

            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached = max(0, (int) $wallet->reserved_cached - $cost);
                $wallet->balance_cached -= $cost;
                $wallet->save();
            }

            $this->siteAllocations->captureUsage((string) $content->client_site_id, $cost, $cost);
            $this->workspaceCredits->adjustReserved((string) $wallet->workspace_id, -$cost);
            $workspaceUsage = $this->workspaceCredits->commitUsage(
                workspaceId: (string) $wallet->workspace_id,
                amount: $cost,
                clientSiteId: (string) $content->client_site_id,
                allocationId: $this->resolveSiteAllocationId((string) $content->client_site_id),
                metadata: [
                    'feature' => 'content_image.generate',
                    'content_id' => (string) $freshImage->content_id,
                    'provider' => (string) data_get($usage->meta, 'provider', ''),
                ],
                referenceType: ContentImage::class,
                referenceId: (string) $freshImage->id,
                idempotencyKey: 'workspace-commit:' . $usageIdempotencyKey
            );

            $freshImage->workspace_credit_wallet_id = $workspaceUsage->workspace_credit_wallet_id;
            $freshImage->workspace_credit_transaction_id = $workspaceUsage->id;
            $freshImage->credit_status = 'committed';
            $freshImage->credit_ledger_entry_id = $usage->id;
            $freshImage->credit_release_reason = null;
            $freshImage->save();

            ContentCreditLog::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => $freshImage->content_id,
                'draft_id' => null,
                'credit_ledger_entry_id' => $usage->id,
                'workspace_credit_transaction_id' => $workspaceUsage->id,
                'event' => 'commit',
                'credits_used' => $cost,
                'mode_multiplier' => 1.0,
                'meta' => [
                    'event_type' => 'image_generation',
                    'content_image_id' => (string) $freshImage->id,
                ],
            ]);

            // Mark CreditReservation as captured
            if ($reservationEntryId !== '') {
                $reservationLedgerEntry = CreditLedgerEntry::query()->find($reservationEntryId);
                if ($reservationLedgerEntry) {
                    $this->markReservationCaptured($reservationLedgerEntry, $usage);
                }
            }

            return $usage;
        });
    }

    public function releaseReservationForContentImage(
        ContentImage $image,
        string $reason = 'release',
        ?string $userId = null
    ): ?CreditLedgerEntry {
        $cost = max(1, (int) ($image->credit_cost ?: config('argusly.ai.images.credit_cost', 6)));
        if (! $image->credit_wallet_id) {
            return null;
        }

        if ($image->credit_status === 'released' && $image->credit_ledger_entry_id) {
            $existingRelease = CreditLedgerEntry::query()->find($image->credit_ledger_entry_id);
            if ($existingRelease) {
                return $existingRelease;
            }
        }

        if ($image->credit_status === 'committed') {
            return $image->credit_ledger_entry_id
                ? CreditLedgerEntry::query()->find($image->credit_ledger_entry_id)
                : null;
        }

        if ($image->credit_status !== 'reserved') {
            return null;
        }

        return DB::transaction(function () use ($image, $cost, $reason, $userId): ?CreditLedgerEntry {
            $freshImage = ContentImage::query()->whereKey($image->id)->lockForUpdate()->firstOrFail();
            if ((string) $freshImage->credit_status !== 'reserved') {
                return $freshImage->credit_ledger_entry_id
                    ? CreditLedgerEntry::query()->find($freshImage->credit_ledger_entry_id)
                    : null;
            }

            $content = Content::query()->find($freshImage->content_id);
            $wallet = CreditWallet::query()
                ->whereKey($freshImage->credit_wallet_id)
                ->lockForUpdate()
                ->first();
            if (! $wallet) {
                return null;
            }

            $reservationEntryId = (string) ($freshImage->credit_ledger_entry_id ?? '');
            $releaseIdempotencyKey = sprintf(
                'content_image:%s:release:%s',
                (string) $freshImage->id,
                $reservationEntryId !== '' ? $reservationEntryId : 'none'
            );
            $existingRelease = CreditLedgerEntry::query()->where('idempotency_key', $releaseIdempotencyKey)->first();
            if ($existingRelease) {
                if ((string) $freshImage->credit_status !== 'released') {
                    $freshImage->credit_status = 'released';
                    $freshImage->credit_ledger_entry_id = $existingRelease->id;
                    $freshImage->credit_release_reason = $reason;
                    $freshImage->save();
                }

                return $existingRelease;
            }

            $release = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => ContentImage::class,
                'source_id' => $freshImage->id,
                'client_site_id' => $content?->client_site_id,
                'organization_id' => $content?->client_site_id
                    ? $this->resolveOrganizationIdByClientSite((string) $content->client_site_id)
                    : null,
                'user_id' => $userId,
                'meta' => [
                    'reservation_entry_id' => $freshImage->credit_ledger_entry_id,
                    'reason' => $reason,
                    'event' => 'content.featured_image.generate',
                    'content_id' => (string) ($freshImage->content_id ?? ''),
                ],
                'idempotency_key' => $releaseIdempotencyKey,
            ]);

            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached = max(0, (int) $wallet->reserved_cached - $cost);
                $wallet->save();
            }

            $this->siteAllocations->releaseReserved((string) $content?->client_site_id, $cost);
            $this->workspaceCredits->adjustReserved((string) $wallet->workspace_id, -$cost);
            $workspaceRelease = $this->workspaceCredits->recordRelease(
                workspaceId: (string) $wallet->workspace_id,
                amount: $cost,
                clientSiteId: (string) ($content?->client_site_id ?? ''),
                allocationId: $content?->client_site_id ? $this->resolveSiteAllocationId((string) $content->client_site_id) : null,
                metadata: [
                    'feature' => 'content_image.generate',
                    'content_id' => (string) ($freshImage->content_id ?? ''),
                    'reason' => $reason,
                ]
            );

            $freshImage->workspace_credit_wallet_id = $workspaceRelease->workspace_credit_wallet_id;
            $freshImage->workspace_credit_transaction_id = $workspaceRelease->id;
            $freshImage->credit_status = 'released';
            $freshImage->credit_ledger_entry_id = $release->id;
            $freshImage->credit_release_reason = $reason;
            $freshImage->save();

            if ($content) {
                ContentCreditLog::query()->create([
                    'id' => (string) Str::uuid(),
                    'content_id' => $freshImage->content_id,
                    'draft_id' => null,
                    'credit_ledger_entry_id' => $release->id,
                    'workspace_credit_transaction_id' => $workspaceRelease->id,
                    'event' => 'release',
                    'credits_used' => $cost,
                    'mode_multiplier' => 1.0,
                    'meta' => [
                        'event_type' => 'image_generation',
                        'reason' => $reason,
                        'content_image_id' => (string) $freshImage->id,
                    ],
                ]);
            }

            // Mark CreditReservation as released
            if ($reservationEntryId !== '') {
                $reservationLedgerEntry = CreditLedgerEntry::query()->find($reservationEntryId);
                if ($reservationLedgerEntry) {
                    $this->markReservationReleased($reservationLedgerEntry, $release, $reason);
                }
            }

            return $release;
        });
    }

    public function addCredits(
        string $clientSiteId,
        int $amount,
        string $type,
        array $meta = [],
        ?string $sourceType = null,
        ?string $sourceId = null,
        $expiresAt = null,
        ?string $idempotencyKey = null
    ): CreditLedgerEntry {
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be positive.');
        }

        if (! in_array($type, [
            self::TYPE_ALLOWANCE,
            self::TYPE_PACK_PURCHASE,
            self::TYPE_REFUND,
            self::TYPE_ADJUSTMENT,
        ], true)) {
            throw new RuntimeException('Invalid credit add type.');
        }

        return DB::transaction(function () use (
            $clientSiteId,
            $amount,
            $type,
            $meta,
            $sourceType,
            $sourceId,
            $expiresAt,
            $idempotencyKey
        ) {
            if ($idempotencyKey) {
                $existing = CreditLedgerEntry::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $site = ClientSite::query()->with('workspace')->find($clientSiteId);
            if (! $site || ! $site->workspace_id) {
                throw new RuntimeException('Client site not found for credit add.');
            }

            $entry = $this->addWorkspaceCredits(
                workspaceId: (string) $site->workspace_id,
                amount: $amount,
                type: $type,
                meta: $meta,
                sourceType: $sourceType,
                sourceId: $sourceId,
                expiresAt: $expiresAt,
                idempotencyKey: $idempotencyKey,
                preferredClientSiteId: $clientSiteId
            );

            if (! $entry) {
                throw new RuntimeException('Failed to create site credit allocation ledger entry.');
            }

            return $entry;
        });
    }

    public function reserveForDraft(Draft $draft, ?string $userId = null): CreditLedgerEntry
    {
        if (! $draft->client_site_id) {
            throw new RuntimeException('Draft has no client_site_id.');
        }

        $billingActorId = $userId
            ?: (string) ($draft->brief()->value('created_by_user_id') ?? '');

        $this->subscriptions->assertClientSiteCanUseGeneration((string) $draft->client_site_id, $billingActorId);

        $cost = $this->resolveCreditCostForDraft($draft);
        if ($cost <= 0) {
            throw new RuntimeException('Draft has no credit_cost.');
        }

        $this->ensureAvailableForClientSite(
            (string) $draft->client_site_id,
            $cost,
            is_numeric($userId) ? (int) $userId : null,
            [
                'feature' => 'draft.generate',
                'draft_id' => (string) $draft->id,
            ]
        );

        if ($draft->credit_status === 'reserved' && $draft->credit_ledger_entry_id) {
            $existingReservation = CreditLedgerEntry::query()->find($draft->credit_ledger_entry_id);
            if ($existingReservation) {
                return $existingReservation;
            }
        }

        if ($draft->credit_status === 'committed' && $draft->credit_ledger_entry_id) {
            $existingUsage = CreditLedgerEntry::query()->find($draft->credit_ledger_entry_id);
            if ($existingUsage) {
                return $existingUsage;
            }
        }

        return DB::transaction(function () use ($draft, $cost, $userId) {
            $idempotencyKey = sprintf('draft:%s:reserve', (string) $draft->id);
            $existingReservation = CreditLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingReservation) {
                if ($draft->credit_status !== 'reserved') {
                    $wallet = CreditWallet::query()
                        ->whereKey($existingReservation->credit_wallet_id)
                        ->lockForUpdate()
                        ->first();

                    $releaseIdempotencyKey = sprintf(
                        'draft:%s:release:%s',
                        (string) $draft->id,
                        (string) $existingReservation->id
                    );
                    $wasReleased = CreditLedgerEntry::query()
                        ->where('idempotency_key', $releaseIdempotencyKey)
                        ->exists();

                    if ($wallet && $wasReleased && ! $this->walletBackedBySiteAllocation($wallet)) {
                        $wallet->reserved_cached += $cost;
                        $wallet->save();
                    }

                    $draft->credit_wallet_id = $existingReservation->credit_wallet_id;
                    $draft->credit_status = 'reserved';
                    $draft->credit_ledger_entry_id = $existingReservation->id;
                    $draft->credit_cost = $cost;
                    $draft->save();
                }

                return $existingReservation;
            }

            $wallet = CreditWallet::query()
                ->where('client_site_id', $draft->client_site_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = CreditWallet::create([
                    'id' => (string) Str::uuid(),
                    'client_site_id' => $draft->client_site_id,
                    'workspace_id' => ClientSite::query()->where('id', $draft->client_site_id)->value('workspace_id'),
                    'balance_cached' => 0,
                    'reserved_cached' => 0,
                ]);
                $wallet->refresh();
            }

            $consumable = $this->getConsumableCreditsForWallet((string) $wallet->id);
            $available = $consumable - (int) $wallet->reserved_cached;

            if ($available < $cost) {
                throw new InsufficientCreditsException($cost, max(0, $available));
            }

            $organizationId = $this->resolveOrganizationIdByClientSite((string) $draft->client_site_id);

            $reservation = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RESERVATION,
                'source' => 'usage',
                'amount' => $cost,
                'remaining' => 0,
                'source_type' => Draft::class,
                'source_id' => $draft->id,
                'brief_id' => $draft->brief_id,
                'client_site_id' => $draft->client_site_id,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'meta' => [
                    'credit_action_id' => $draft->credit_action_id,
                    'output_type' => $draft->output_type,
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached += $cost;
                $wallet->save();
            }

            $this->ensureAvailableForClientSite(
                (string) $draft->client_site_id,
                $cost,
                is_numeric($userId) ? (int) $userId : null,
                [
                    'feature' => 'draft.generate',
                    'draft_id' => (string) $draft->id,
                    'event' => 'auto_allocate_for_locked_reservation',
                ]
            );

            $this->siteAllocations->reserve((string) $draft->client_site_id, $cost);
            $this->workspaceCredits->adjustReserved((string) $wallet->workspace_id, $cost);
            $workspaceReservation = $this->workspaceCredits->recordReservation(
                workspaceId: (string) $wallet->workspace_id,
                amount: $cost,
                clientSiteId: (string) $draft->client_site_id,
                allocationId: $this->resolveSiteAllocationId((string) $draft->client_site_id),
                metadata: [
                    'feature' => 'draft.generate',
                    'draft_id' => (string) $draft->id,
                    'output_type' => (string) $draft->output_type,
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            $draft->credit_wallet_id = $wallet->id;
            $draft->workspace_credit_wallet_id = $workspaceReservation->workspace_credit_wallet_id;
            $draft->credit_status = 'reserved';
            $draft->credit_ledger_entry_id = $reservation->id;
            $draft->workspace_credit_transaction_id = $workspaceReservation->id;
            $draft->credit_cost = $cost;
            $draft->save();

            // Create CreditReservation record for tracking and admin management
            $this->createReservationRecord(
                wallet: $wallet,
                ledgerEntry: $reservation,
                context: $draft,
                purpose: 'draft_generate',
                userId: $userId,
                organizationId: $organizationId,
                reservationWorkspaceTransactionId: $workspaceReservation->id
            );

            if ($draft->content_id) {
                ContentCreditLog::query()->create([
                    'id' => (string) Str::uuid(),
                    'content_id' => $draft->content_id,
                    'draft_id' => $draft->id,
                    'credit_ledger_entry_id' => $reservation->id,
                    'workspace_credit_transaction_id' => $workspaceReservation->id,
                    'event' => 'reserve',
                    'credits_used' => $cost,
                    'mode_multiplier' => 1.0,
                ]);
            }

            return $reservation;
        });
    }

    public function commitUsageForDraft(Draft $draft, ?string $userId = null): CreditLedgerEntry
    {
        $cost = (int) ($draft->credit_cost ?? 0);
        if ($cost <= 0) {
            throw new RuntimeException('Draft has no credit_cost.');
        }

        if (! $draft->credit_wallet_id) {
            throw new RuntimeException('Draft has no credit_wallet_id.');
        }

        if ($draft->credit_status === 'committed' && $draft->credit_ledger_entry_id) {
            $existingUsage = CreditLedgerEntry::query()->find($draft->credit_ledger_entry_id);
            if ($existingUsage) {
                return $existingUsage;
            }
        }

        if ($draft->credit_status !== 'reserved') {
            throw new RuntimeException('Draft is not in reserved status.');
        }

        return DB::transaction(function () use ($draft, $cost, $userId) {
            $generationMeta = is_array($draft->meta) ? (array) data_get($draft->meta, 'generation', []) : [];
            $provider = strtolower(trim((string) ($generationMeta['provider'] ?? 'openai')));
            $tokenFactor = (float) config('llm.pricing.token_factor.' . $provider, 1.0);
            $effectiveTokens = (int) round(((int) ($generationMeta['tokens'] ?? 0)) * max(0.0, $tokenFactor));

            $wallet = CreditWallet::query()
                ->whereKey($draft->credit_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $reservationEntryId = (string) ($draft->credit_ledger_entry_id ?? '');
            $usageIdempotencyKey = sprintf('draft:%s:usage:%s', (string) $draft->id, $reservationEntryId !== '' ? $reservationEntryId : 'none');
            $existingUsage = CreditLedgerEntry::query()->where('idempotency_key', $usageIdempotencyKey)->first();
            if ($existingUsage) {
                if ($draft->credit_status !== 'committed') {
                    $draft->credit_status = 'committed';
                    $draft->credit_ledger_entry_id = $existingUsage->id;
                    $draft->save();
                }

                return $existingUsage;
            }

            $release = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => Draft::class,
                'source_id' => $draft->id,
                'brief_id' => $draft->brief_id,
                'client_site_id' => $draft->client_site_id,
                'organization_id' => $this->resolveOrganizationIdByClientSite((string) $draft->client_site_id),
                'user_id' => $userId,
                'meta' => [
                    'reservation_entry_id' => $draft->credit_ledger_entry_id,
                ],
                'idempotency_key' => sprintf(
                    'draft:%s:release-for-commit:%s',
                    (string) $draft->id,
                    $reservationEntryId !== '' ? $reservationEntryId : 'none'
                ),
            ]);

            $allocations = $this->consumeFromBuckets($wallet, $cost);

            $usage = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_USAGE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => Draft::class,
                'source_id' => $draft->id,
                'brief_id' => $draft->brief_id,
                'client_site_id' => $draft->client_site_id,
                'organization_id' => $this->resolveOrganizationIdByClientSite((string) $draft->client_site_id),
                'user_id' => $userId,
                'meta' => [
                    'credit_action_id' => $draft->credit_action_id,
                    'output_type' => $draft->output_type,
                    'reservation_entry_id' => $draft->credit_ledger_entry_id,
                    'release_entry_id' => $release->id,
                    'llm_provider' => $provider,
                    'llm_model' => (string) ($generationMeta['model'] ?? ''),
                    'llm_input_tokens' => (int) ($generationMeta['input_tokens'] ?? 0),
                    'llm_output_tokens' => (int) ($generationMeta['output_tokens'] ?? 0),
                    'llm_total_tokens' => (int) ($generationMeta['tokens'] ?? 0),
                    'llm_effective_tokens' => $effectiveTokens,
                    'llm_request_id' => (string) ($generationMeta['request_id'] ?? ''),
                    'llm_token_factor' => $tokenFactor,
                    'allocations' => $allocations,
                    'consumption_policy' => 'included_first_then_addon',
                ],
                'idempotency_key' => $usageIdempotencyKey,
            ]);

            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached -= $cost;
                $wallet->balance_cached -= $cost;
                $wallet->save();
            }

            $this->siteAllocations->captureUsage((string) $draft->client_site_id, $cost, $cost);
            $this->workspaceCredits->adjustReserved((string) $wallet->workspace_id, -$cost);
            $workspaceUsage = $this->workspaceCredits->commitUsage(
                workspaceId: (string) $wallet->workspace_id,
                amount: $cost,
                clientSiteId: (string) $draft->client_site_id,
                allocationId: $this->resolveSiteAllocationId((string) $draft->client_site_id),
                metadata: [
                    'feature' => 'draft.generate',
                    'output_type' => (string) $draft->output_type,
                    'provider' => $provider,
                    'model' => (string) ($generationMeta['model'] ?? ''),
                ],
                referenceType: Draft::class,
                referenceId: (string) $draft->id,
                idempotencyKey: 'workspace-commit:' . $usageIdempotencyKey
            );

            $draft->workspace_credit_wallet_id = $workspaceUsage->workspace_credit_wallet_id;
            $draft->workspace_credit_transaction_id = $workspaceUsage->id;
            $draft->credit_status = 'committed';
            $draft->credit_ledger_entry_id = $usage->id;
            $draft->save();

            if ($draft->content_id) {
                ContentCreditLog::query()->create([
                    'id' => (string) Str::uuid(),
                    'content_id' => $draft->content_id,
                    'draft_id' => $draft->id,
                    'credit_ledger_entry_id' => $usage->id,
                    'workspace_credit_transaction_id' => $workspaceUsage->id,
                    'event' => 'commit',
                    'credits_used' => $cost,
                    'mode_multiplier' => 1.0,
                    'meta' => [
                        'provider' => $provider,
                        'model' => (string) ($generationMeta['model'] ?? ''),
                        'input_tokens' => (int) ($generationMeta['input_tokens'] ?? 0),
                        'output_tokens' => (int) ($generationMeta['output_tokens'] ?? 0),
                        'total_tokens' => (int) ($generationMeta['tokens'] ?? 0),
                        'effective_tokens' => $effectiveTokens,
                        'request_id' => (string) ($generationMeta['request_id'] ?? ''),
                        'token_factor' => $tokenFactor,
                    ],
                ]);
            }

            // Mark CreditReservation as captured
            if ($reservationEntryId !== '') {
                $reservationLedgerEntry = CreditLedgerEntry::query()->find($reservationEntryId);
                if ($reservationLedgerEntry) {
                    $this->markReservationCaptured($reservationLedgerEntry, $usage);
                }
            }

            return $usage;
        });
    }

    public function releaseReservationForDraft(Draft $draft, ?string $userId = null, string $reason = 'release'): CreditLedgerEntry
    {
        $cost = (int) ($draft->credit_cost ?? 0);
        if ($cost <= 0) {
            throw new RuntimeException('Draft has no credit_cost.');
        }

        if (! $draft->credit_wallet_id) {
            throw new RuntimeException('Draft has no credit_wallet_id.');
        }

        if ($draft->credit_status === 'released' && $draft->credit_ledger_entry_id) {
            $existingRelease = CreditLedgerEntry::query()->find($draft->credit_ledger_entry_id);
            if ($existingRelease) {
                return $existingRelease;
            }
        }

        if ($draft->credit_status !== 'reserved') {
            throw new RuntimeException('Draft is not in reserved status.');
        }

        return DB::transaction(function () use ($draft, $cost, $userId, $reason) {
            $wallet = CreditWallet::query()
                ->whereKey($draft->credit_wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $reservationEntryId = (string) ($draft->credit_ledger_entry_id ?? '');
            $releaseIdempotencyKey = sprintf('draft:%s:release:%s', (string) $draft->id, $reservationEntryId !== '' ? $reservationEntryId : 'none');
            $existingRelease = CreditLedgerEntry::query()->where('idempotency_key', $releaseIdempotencyKey)->first();
            if ($existingRelease) {
                if ($draft->credit_status !== 'released') {
                    $draft->credit_status = 'released';
                    $draft->credit_ledger_entry_id = $existingRelease->id;
                    $draft->save();
                }

                return $existingRelease;
            }

            $release = CreditLedgerEntry::create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => $wallet->id,
                'type' => self::TYPE_RELEASE,
                'source' => 'usage',
                'amount' => -$cost,
                'remaining' => 0,
                'source_type' => Draft::class,
                'source_id' => $draft->id,
                'brief_id' => $draft->brief_id,
                'client_site_id' => $draft->client_site_id,
                'organization_id' => $this->resolveOrganizationIdByClientSite((string) $draft->client_site_id),
                'user_id' => $userId,
                'meta' => [
                    'reservation_entry_id' => $draft->credit_ledger_entry_id,
                    'reason' => $reason,
                ],
                'idempotency_key' => $releaseIdempotencyKey,
            ]);

            if (! $this->walletBackedBySiteAllocation($wallet)) {
                $wallet->reserved_cached -= $cost;
                $wallet->save();
            }

            $this->siteAllocations->releaseReserved((string) $draft->client_site_id, $cost);
            $this->workspaceCredits->adjustReserved((string) $wallet->workspace_id, -$cost);
            $workspaceRelease = $this->workspaceCredits->recordRelease(
                workspaceId: (string) $wallet->workspace_id,
                amount: $cost,
                clientSiteId: (string) $draft->client_site_id,
                allocationId: $this->resolveSiteAllocationId((string) $draft->client_site_id),
                metadata: [
                    'feature' => 'draft.generate',
                    'draft_id' => (string) $draft->id,
                    'reason' => $reason,
                ]
            );

            $draft->workspace_credit_wallet_id = $workspaceRelease->workspace_credit_wallet_id;
            $draft->workspace_credit_transaction_id = $workspaceRelease->id;
            $draft->credit_status = 'released';
            $draft->credit_ledger_entry_id = $release->id;
            $draft->save();

            if ($draft->content_id) {
                ContentCreditLog::query()->create([
                    'id' => (string) Str::uuid(),
                    'content_id' => $draft->content_id,
                    'draft_id' => $draft->id,
                    'credit_ledger_entry_id' => $release->id,
                    'workspace_credit_transaction_id' => $workspaceRelease->id,
                    'event' => 'release',
                    'credits_used' => $cost,
                    'mode_multiplier' => 1.0,
                ]);
            }

            // Mark CreditReservation as released
            if ($reservationEntryId !== '') {
                $reservationLedgerEntry = CreditLedgerEntry::query()->find($reservationEntryId);
                if ($reservationLedgerEntry) {
                    $this->markReservationReleased($reservationLedgerEntry, $release, $reason);
                }
            }

            return $release;
        });
    }

    public function ensureReservedForDraft(Draft $draft, ?string $userId = null): ?CreditLedgerEntry
    {
        $draft->refresh();
        $status = (string) ($draft->credit_status ?? '');

        if ($status === 'reserved' || $status === 'committed' || $status === 'released') {
            return $draft->credit_ledger_entry_id
                ? CreditLedgerEntry::query()->find($draft->credit_ledger_entry_id)
                : null;
        }

        return $this->reserveForDraft($draft, $userId);
    }

    public function ensureCommittedForDraft(Draft $draft, ?string $userId = null): ?CreditLedgerEntry
    {
        $draft->refresh();
        $status = (string) ($draft->credit_status ?? '');

        if ($status === 'committed') {
            return $draft->credit_ledger_entry_id
                ? CreditLedgerEntry::query()->find($draft->credit_ledger_entry_id)
                : null;
        }

        if ($status !== 'reserved') {
            return null;
        }

        return $this->commitUsageForDraft($draft, $userId);
    }

    public function ensureReleasedForDraft(Draft $draft, ?string $userId = null, string $reason = 'release'): ?CreditLedgerEntry
    {
        $draft->refresh();
        $status = (string) ($draft->credit_status ?? '');

        if ($status === 'released' || $status === 'committed') {
            return $draft->credit_ledger_entry_id
                ? CreditLedgerEntry::query()->find($draft->credit_ledger_entry_id)
                : null;
        }

        if ($status !== 'reserved') {
            return null;
        }

        return $this->releaseReservationForDraft($draft, $userId, $reason);
    }

    public function ensureReleasedForContentImage(
        ContentImage $image,
        string $reason = 'release',
        ?string $userId = null
    ): ?CreditLedgerEntry {
        $image->refresh();

        $status = (string) ($image->credit_status ?? '');

        if (! $image->hasOutput() && in_array($status, ['pending', 'committed', 'failed', 'canceled', 'expired'], true)) {
            return $this->refundCommittedContentImageUsage($image, $reason);
        }

        return $this->releaseReservationForContentImage($image, $reason, $userId);
    }

    private function refundCommittedContentImageUsage(ContentImage $image, string $reason): ?CreditLedgerEntry
    {
        return DB::transaction(function () use ($image, $reason): ?CreditLedgerEntry {
            $freshImage = ContentImage::query()->whereKey($image->id)->lockForUpdate()->first();
            if (! $freshImage) {
                return null;
            }

            if ($freshImage->hasOutput()) {
                return $freshImage->credit_ledger_entry_id
                    ? CreditLedgerEntry::query()->find($freshImage->credit_ledger_entry_id)
                    : null;
            }

            $currentLedger = $freshImage->credit_ledger_entry_id
                ? CreditLedgerEntry::query()->find($freshImage->credit_ledger_entry_id)
                : null;

            if ((string) ($freshImage->credit_status ?? '') === 'released' && $currentLedger) {
                return $currentLedger;
            }

            $usageEntry = $this->findUsageEntryForContentImage($freshImage, $currentLedger);

            if (! $usageEntry) {
                return null;
            }

            $cost = max(1, abs((int) $usageEntry->amount));
            $idempotencyKey = sprintf(
                'content_image:%s:refund:%s',
                (string) $freshImage->id,
                (string) $usageEntry->id
            );

            $refund = $this->addCredits(
                clientSiteId: (string) $usageEntry->client_site_id,
                amount: $cost,
                type: self::TYPE_REFUND,
                meta: [
                    'event' => 'content.featured_image.generate',
                    'reason' => $reason,
                    'usage_entry_id' => (string) $usageEntry->id,
                    'refunded_for_content_image_id' => (string) $freshImage->id,
                ],
                sourceType: ContentImage::class,
                sourceId: (string) $freshImage->id,
                idempotencyKey: $idempotencyKey
            );

            $freshImage->credit_status = 'released';
            $freshImage->credit_ledger_entry_id = $refund->id;
            $freshImage->credit_release_reason = $reason;
            $freshImage->save();

            return $refund;
        });
    }

    private function findUsageEntryForContentImage(
        ContentImage $image,
        ?CreditLedgerEntry $currentLedger = null
    ): ?CreditLedgerEntry {
        if ($currentLedger && (string) $currentLedger->type === self::TYPE_USAGE) {
            return $currentLedger;
        }

        $direct = CreditLedgerEntry::query()
            ->where('source_type', ContentImage::class)
            ->where('source_id', $image->id)
            ->where('type', self::TYPE_USAGE)
            ->latest('created_at')
            ->first();
        if ($direct) {
            return $direct;
        }

        // Legacy fallback: older image charges were recorded against Content.
        $candidates = CreditLedgerEntry::query()
            ->where('source_type', Content::class)
            ->where('source_id', (string) $image->content_id)
            ->where('type', self::TYPE_USAGE)
            ->where(function ($query): void {
                $query->whereJsonContains('meta->event', 'content.featured_image.generate')
                    ->orWhereJsonContains('meta->event_type', 'image_generation');
            })
            ->latest('created_at')
            ->limit(10)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $targetTs = optional($image->created_at)?->getTimestamp() ?? now()->getTimestamp();

        return $candidates->sortBy(function (CreditLedgerEntry $entry) use ($targetTs): int {
            return abs((optional($entry->created_at)?->getTimestamp() ?? $targetTs) - $targetTs);
        })->first();
    }

    public function expireAddonCredits(int $limit = 200): int
    {
        return $this->siteAllocations->expireAddonCredits($limit);
    }

    public function getSummary(string $clientSiteId): array
    {
        $wallet = $this->getOrCreateWalletForClientSite($clientSiteId);
        $allocation = SiteCreditAllocation::query()->where('client_site_id', $clientSiteId)->first();

        return [
            'wallet_id' => $wallet->id,
            'balance_cached' => (int) ($allocation?->allocated_credits ?? $wallet->balance_cached),
            'reserved_cached' => (int) ($allocation?->reserved_cached ?? $wallet->reserved_cached),
            'available' => (int) ($allocation?->remaining ?? $wallet->available),
            'used_cached' => (int) ($allocation?->used_cached ?? 0),
            'included_remaining' => $this->remainingBySource((string) $wallet->id, 'included_plan'),
            'addon_remaining' => $this->remainingBySource((string) $wallet->id, 'addon_pack'),
        ];
    }

    public function estimateDraftRequiredCredits(Draft $draft): int
    {
        $current = (int) ($draft->credit_cost ?? 0);
        if ($current > 0) {
            return $current;
        }

        return $this->resolveCreditCostForOutputType((string) ($draft->output_type ?? 'kb_article'));
    }

    public function estimateRequiredCreditsForOutputType(string $outputType): int
    {
        return $this->resolveCreditCostForOutputType($outputType);
    }

    public function getUsageByAction(
        string $clientSiteId,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null
    ): array {
        $wallet = $this->getOrCreateWalletForClientSite($clientSiteId);

        $q = CreditLedgerEntry::query()
            ->where('credit_wallet_id', $wallet->id)
            ->where('type', self::TYPE_USAGE);

        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        if ($to) {
            $q->where('created_at', '<=', $to);
        }

        $rows = $q->get(['amount', 'meta', 'created_at']);

        $byAction = [];
        $totalUsed = 0;

        foreach ($rows as $row) {
            $used = abs((int) $row->amount);
            $totalUsed += $used;

            $actionId = (string) data_get($row->meta, 'credit_action_id', 'unknown');
            $byAction[$actionId] = ($byAction[$actionId] ?? 0) + $used;
        }

        arsort($byAction);

        return [
            'total_used' => $totalUsed,
            'by_action_id' => $byAction,
        ];
    }

    public function getLedger(string $clientSiteId, int $limit = 50): Collection
    {
        $wallet = $this->getOrCreateWalletForClientSite($clientSiteId);

        return CreditLedgerEntry::query()
            ->where('credit_wallet_id', $wallet->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    private function consumeFromBuckets(CreditWallet $wallet, int $amount): array
    {
        return $this->siteAllocations->consumeAllocatedCredits((string) $wallet->client_site_id, $amount);
    }

    private function resolveSourceFromType(string $type): string
    {
        return match ($type) {
            self::TYPE_ALLOWANCE => 'included_plan',
            self::TYPE_PACK_PURCHASE, self::TYPE_REFUND, self::TYPE_ADJUSTMENT => 'addon_pack',
            default => 'usage',
        };
    }

    private function getUnallocatedWorkspaceCredits(string $workspaceId): int
    {
        if ($workspaceId === '') {
            return 0;
        }

        return (int) WorkspaceCreditTransaction::query()
            ->where('workspace_id', $workspaceId)
            ->where('remaining', '>', 0)
            ->whereIn('source', ['included_plan', 'addon_pack'])
            ->whereNotIn('id', SiteCreditAllocationBucket::query()
                ->select('workspace_credit_transaction_id')
                ->whereNotNull('workspace_credit_transaction_id'))
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum('remaining');
    }

    private function resolveOrganizationIdByClientSite(string $clientSiteId): ?int
    {
        $site = ClientSite::query()->with('workspace')->find($clientSiteId);

        return $site?->workspace?->organization_id;
    }

    private function walletBackedBySiteAllocation(CreditWallet $wallet): bool
    {
        return $wallet->getTable() === 'site_credit_allocations';
    }

    private function resolveCreditCostForDraft(Draft $draft): int
    {
        $current = (int) ($draft->credit_cost ?? 0);
        if ($current > 0) {
            return $current;
        }

        $outputType = (string) ($draft->output_type ?: 'kb_article');
        $preferredActionKey = $this->preferredCreditActionKeyForOutputType($outputType);

        $action = CreditAction::query()
            ->where('key', $preferredActionKey)
            ->where('is_active', true)
            ->first();

        if (! $action) {
            $action = CreditAction::query()
                ->where('category', 'content')
                ->where('is_active', true)
                ->orderBy('credits_cost')
                ->first();
        }

        $resolvedCost = $action
            ? (int) $action->credits_cost
            : max(1, (int) config('argusly.ai.drafts.credit_cost', 4));

        $draft->credit_action_id = $draft->credit_action_id ?: $action?->id;
        $draft->credit_cost = $resolvedCost;
        $draft->save();

        return $resolvedCost;
    }

    private function resolveCreditCostForOutputType(string $outputType): int
    {
        $preferredActionKey = $this->preferredCreditActionKeyForOutputType($outputType);

        $action = CreditAction::query()
            ->where('key', $preferredActionKey)
            ->where('is_active', true)
            ->first();

        if (! $action) {
            $action = CreditAction::query()
                ->where('category', 'content')
                ->where('is_active', true)
                ->orderBy('credits_cost')
                ->first();
        }

        return $action
            ? max(1, (int) $action->credits_cost)
            : max(1, (int) config('argusly.ai.drafts.credit_cost', 4));
    }

    private function preferredCreditActionKeyForOutputType(string $outputType): string
    {
        return match (strtolower(trim($outputType))) {
            'faq', 'faq_set' => 'content.faq_set',
            'outline' => 'content.outline',
            'brief' => 'content.brief',
            default => 'content.article',
        };
    }

    private function getConsumableCreditsForWallet(string $walletId): int
    {
        $clientSiteId = CreditWallet::query()->whereKey($walletId)->value('client_site_id');

        return $clientSiteId
            ? $this->siteAllocations->consumableCreditsForSite((string) $clientSiteId)
            : 0;
    }

    private function remainingBySource(string $walletId, string $source): int
    {
        $clientSiteId = CreditWallet::query()->whereKey($walletId)->value('client_site_id');

        return $clientSiteId
            ? $this->siteAllocations->remainingBySource((string) $clientSiteId, $source)
            : 0;
    }

    /**
     * Create a CreditReservation record for tracking and admin management.
     */
    private function createReservationRecord(
        CreditWallet $wallet,
        CreditLedgerEntry $ledgerEntry,
        $context,
        string $purpose,
        ?string $userId,
        ?int $organizationId,
        ?string $reservationWorkspaceTransactionId = null
    ): CreditReservation {
        $idempotencyKey = sprintf('reservation:%s', $ledgerEntry->idempotency_key ?? $ledgerEntry->id);

        $existing = CreditReservation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $site = ClientSite::query()->find($wallet->client_site_id);
        $workspaceWalletId = $site?->workspace_id
            ? $this->workspaceCredits->getOrCreateWallet((string) $site->workspace_id, $organizationId)->id
            : null;

        return CreditReservation::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'workspace_id' => $site?->workspace_id,
            'workspace_credit_wallet_id' => $workspaceWalletId,
            'client_site_id' => $wallet->client_site_id,
            'credit_wallet_id' => $wallet->id,
            'user_id' => $userId,
            'amount' => (int) $ledgerEntry->amount,
            'currency_unit' => 'credits',
            'status' => CreditReservation::STATUS_RESERVED,
            'context_type' => $context ? get_class($context) : null,
            'context_id' => $context?->id,
            'provider' => null,
            'purpose' => $purpose,
            'idempotency_key' => $idempotencyKey,
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(CreditReservation::defaultTtlMinutes()),
            'reservation_ledger_entry_id' => $ledgerEntry->id,
            'reservation_workspace_transaction_id' => $reservationWorkspaceTransactionId,
            'metadata' => [
                'source_idempotency_key' => $ledgerEntry->idempotency_key,
            ],
        ]);
    }

    /**
     * Mark a CreditReservation as captured.
     */
    private function markReservationCaptured(
        CreditLedgerEntry $reservationLedgerEntry,
        CreditLedgerEntry $usageLedgerEntry
    ): void {
        $reservation = CreditReservation::query()
            ->where('reservation_ledger_entry_id', $reservationLedgerEntry->id)
            ->where('status', CreditReservation::STATUS_RESERVED)
            ->first();

        if (! $reservation) {
            return;
        }

        $reservation->update([
            'status' => CreditReservation::STATUS_CAPTURED,
            'captured_at' => now(),
            'capture_ledger_entry_id' => $usageLedgerEntry->id,
            'capture_workspace_transaction_id' => $this->resolveWorkspaceTransactionIdFromIdempotency('workspace-commit:' . (string) $usageLedgerEntry->idempotency_key),
        ]);

        Log::info('Credit reservation captured', [
            'reservation_id' => $reservation->id,
            'amount' => $reservation->amount,
        ]);
    }

    /**
     * Mark a CreditReservation as released.
     */
    private function markReservationReleased(
        CreditLedgerEntry $reservationLedgerEntry,
        CreditLedgerEntry $releaseLedgerEntry,
        string $reason = 'release'
    ): void {
        $reservation = CreditReservation::query()
            ->where('reservation_ledger_entry_id', $reservationLedgerEntry->id)
            ->where('status', CreditReservation::STATUS_RESERVED)
            ->first();

        if (! $reservation) {
            return;
        }

        $reservation->update([
            'status' => CreditReservation::STATUS_RELEASED,
            'released_at' => now(),
            'release_ledger_entry_id' => $releaseLedgerEntry->id,
            'release_workspace_transaction_id' => $this->resolveWorkspaceReleaseTransactionId((string) $reservation->id),
            'reason' => $reason,
        ]);

        Log::info('Credit reservation released', [
            'reservation_id' => $reservation->id,
            'amount' => $reservation->amount,
            'reason' => $reason,
        ]);
    }

    private function resolveSiteAllocationId(string $clientSiteId): ?string
    {
        return SiteCreditAllocation::query()->where('client_site_id', $clientSiteId)->value('id');
    }

    private function resolveWorkspaceTransactionIdFromIdempotency(?string $idempotencyKey): ?string
    {
        if (! $idempotencyKey) {
            return null;
        }

        return \App\Models\WorkspaceCreditTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->value('id');
    }

    private function resolveWorkspaceReleaseTransactionId(string $reservationId): ?string
    {
        return \App\Models\WorkspaceCreditTransaction::query()
            ->where('credit_reservation_id', $reservationId)
            ->where('type', WorkspaceCreditLedgerService::TYPE_RELEASE)
            ->value('id');
    }
}
