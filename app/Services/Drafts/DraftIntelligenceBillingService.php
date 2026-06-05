<?php

namespace App\Services\Drafts;

use App\Models\ContentCreditLog;
use App\Models\CreditAction;
use App\Models\CreditReservation;
use App\Models\Draft;
use App\Services\CreditReservationService;
use RuntimeException;

class DraftIntelligenceBillingService
{
    public const PURPOSE_ANALYSIS = 'draft_analysis';

    public const PURPOSE_IMPROVEMENT = 'draft_improvement';

    public function __construct(
        private readonly CreditReservationService $reservations,
    ) {}

    public function reserveForAnalysis(Draft $draft, ?string $userId = null, ?string $suffix = null): CreditReservation
    {
        return $this->reserve(
            draft: $draft,
            purpose: self::PURPOSE_ANALYSIS,
            actionKey: (string) config('draft_intelligence.analysis_action_key', 'draft.analysis'),
            userId: $userId,
            suffix: $suffix,
        );
    }

    public function reserveForImprovement(Draft $draft, ?string $userId = null, ?string $suffix = null): CreditReservation
    {
        return $this->reserve(
            draft: $draft,
            purpose: self::PURPOSE_IMPROVEMENT,
            actionKey: (string) config('draft_intelligence.improvement_action_key', 'draft.improvement'),
            userId: $userId,
            suffix: $suffix,
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function capture(CreditReservation $reservation, Draft $draft, array $metadata = [], ?string $userId = null): CreditReservation
    {
        $captured = $this->reservations->capture($reservation, [
            'userId' => $userId,
            'metadata' => $metadata,
        ]);

        $this->appendTransactionMetadata($draft, $captured, $metadata);

        return $captured;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function release(CreditReservation $reservation, Draft $draft, string $reason, array $metadata = [], ?string $userId = null): CreditReservation
    {
        $released = $this->reservations->release($reservation, $reason, [
            'userId' => $userId,
            'metadata' => $metadata,
        ]);

        $this->appendTransactionMetadata($draft, $released, array_merge($metadata, ['release_reason' => $reason]));

        return $released;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function reserve(
        Draft $draft,
        string $purpose,
        string $actionKey,
        ?string $userId = null,
        ?string $suffix = null,
        array $metadata = [],
    ): CreditReservation {
        $action = CreditAction::query()
            ->where('key', $actionKey)
            ->where('is_active', true)
            ->first();

        if (! $action) {
            $action = CreditAction::query()->create([
                'key' => $actionKey,
                'category' => 'draft',
                'credits_cost' => 1,
                'label_nl' => $purpose === self::PURPOSE_ANALYSIS ? 'Draft intelligence: analyse' : 'Draft intelligence: verbetering',
                'label_en' => $purpose === self::PURPOSE_ANALYSIS ? 'Draft intelligence: analysis' : 'Draft intelligence: improvement',
                'is_active' => true,
                'meta' => [
                    'display_credits_cost' => $purpose === self::PURPOSE_ANALYSIS
                        ? (float) config('draft_intelligence.analysis_display_credits', 0.2)
                        : (float) config('draft_intelligence.improvement_display_credits', 0.5),
                    'transaction_type' => $purpose,
                    'billing_note' => 'Auto-created fallback action because draft intelligence pricing seed was missing.',
                ],
            ]);
        }

        if (! $draft->client_site_id) {
            throw new RuntimeException('Draft has no connected site for credit reservation.');
        }

        $idempotencyKey = $this->buildReservationIdempotencyKey($draft, $purpose, $suffix);

        return $this->reservations->reserve(
            clientSiteId: (string) $draft->client_site_id,
            amount: max(1, (int) $action->credits_cost),
            idempotencyKey: $idempotencyKey,
            purpose: $purpose,
            context: $draft,
            options: [
                'userId' => $userId,
                'metadata' => array_merge($metadata, [
                    'credit_action_id' => (string) $action->id,
                    'credit_action_key' => $action->key,
                    'display_credits_cost' => data_get($action->meta, 'display_credits_cost'),
                    'billing_note' => data_get($action->meta, 'billing_note'),
                    'transaction_type' => $purpose,
                ]),
            ],
        );
    }

    private function buildReservationIdempotencyKey(Draft $draft, string $purpose, ?string $suffix = null): string
    {
        $normalizedSuffix = trim((string) $suffix);

        if ($normalizedSuffix === '') {
            return sprintf('draft_intelligence:%s:%s', $draft->id, $purpose);
        }

        return sprintf(
            'draft_intelligence:%s:%s:%s',
            $draft->id,
            $purpose,
            substr(sha1($normalizedSuffix), 0, 20),
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function appendTransactionMetadata(Draft $draft, CreditReservation $reservation, array $metadata): void
    {
        if (! $draft->content_id) {
            return;
        }

        $purpose = (string) ($reservation->purpose ?? '');
        $workspaceTransactionId = $reservation->capture_workspace_transaction_id ?: $reservation->release_workspace_transaction_id;
        $ledgerEntryId = $reservation->capture_ledger_entry_id ?: $reservation->release_ledger_entry_id;

        ContentCreditLog::query()
            ->where('draft_id', $draft->id)
            ->when($workspaceTransactionId, fn ($query) => $query->where('workspace_credit_transaction_id', $workspaceTransactionId))
            ->when(! $workspaceTransactionId && $ledgerEntryId, fn ($query) => $query->where('credit_ledger_entry_id', $ledgerEntryId))
            ->latest('created_at')
            ->limit(1)
            ->get()
            ->each(function (ContentCreditLog $log) use ($purpose, $metadata): void {
                $meta = is_array($log->meta) ? $log->meta : [];
                $meta['transaction_type'] = $purpose;
                $meta = array_merge($meta, $metadata);
                $log->forceFill(['meta' => $meta])->save();
            });
    }
}
