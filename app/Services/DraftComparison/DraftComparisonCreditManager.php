<?php

namespace App\Services\DraftComparison;

use App\Models\CreditReservation;
use App\Models\DraftComparison;
use App\Services\CreditReservationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DraftComparisonCreditManager
{
    public function __construct(
        private readonly CreditReservationService $reservations,
        private readonly DraftComparisonCreditEstimator $estimator,
    ) {}

    /**
     * @param array<int, array{provider?:string,model?:string,key?:string}> $selections
     * @return array<string, mixed>
     */
    public function estimateForComparison(
        \App\Models\Brief $brief,
        array $selections,
        ?int $requestedMaxOutputTokens = null,
        bool $includeScoring = false,
        bool $includeHybrid = false,
    ): array {
        return $this->estimator->estimateForComparison(
            brief: $brief,
            selections: $selections,
            requestedMaxOutputTokens: $requestedMaxOutputTokens,
            includeScoring: $includeScoring,
            includeHybrid: $includeHybrid,
        );
    }

    public function reserveForComparison(
        DraftComparison $comparison,
        int $amount,
        ?int $userId = null,
        array $metadata = [],
    ): ?CreditReservation {
        if ($amount <= 0) {
            return null;
        }

        if (! $comparison->client_site_id) {
            throw new RuntimeException('Draft comparison has no client_site_id.');
        }

        $reservation = $this->reservations->reserve(
            clientSiteId: (string) $comparison->client_site_id,
            amount: $amount,
            idempotencyKey: $this->reservationIdempotencyKey($comparison),
            purpose: 'draft_compare_generate',
            context: $comparison,
            options: [
                'userId' => $userId,
                'metadata' => array_merge($metadata, [
                    'comparison_id' => (string) $comparison->id,
                ]),
            ],
        );

        $this->persistBillingState(
            comparison: $comparison,
            reservation: $reservation,
            state: 'reserved',
            extra: [
                'reserved_credit_amount' => (int) $reservation->amount,
                'estimated_credit_cost' => (int) ($comparison->estimated_credit_cost ?? $amount),
            ],
        );

        return $reservation;
    }

    /**
     * @param array<string, mixed> $usage
     */
    public function recordVariantUsage(
        DraftComparison $comparison,
        string $variantKey,
        int $credits,
        array $usage = [],
    ): DraftComparison {
        $normalizedCredits = max(0, $credits);

        return DB::transaction(function () use ($comparison, $variantKey, $normalizedCredits, $usage): DraftComparison {
            $locked = DraftComparison::query()
                ->whereKey($comparison->id)
                ->lockForUpdate()
                ->firstOrFail();

            $summary = is_array($locked->comparison_summary_json) ? $locked->comparison_summary_json : [];
            $billing = is_array($summary['billing'] ?? null) ? $summary['billing'] : [];
            $variantUsage = is_array($billing['variant_usage'] ?? null) ? $billing['variant_usage'] : [];

            if (trim($variantKey) !== '') {
                $variantUsage[$variantKey] = array_filter(array_merge(
                    [
                        'credits' => $normalizedCredits,
                        'recorded_at' => now()->toIso8601String(),
                    ],
                    $usage,
                ), static fn (mixed $value): bool => $value !== null);
            }

            $billing['variant_usage'] = $variantUsage;
            $summary['billing'] = $billing;

            $locked->comparison_summary_json = $summary;
            $locked->save();

            return $locked;
        });
    }

    public function settleComparison(
        DraftComparison $comparison,
        ?int $userId = null,
        array $options = [],
    ): DraftComparison {
        $force = (bool) ($options['force'] ?? false);

        return DB::transaction(function () use ($comparison, $userId, $options, $force): DraftComparison {
            $locked = DraftComparison::query()
                ->whereKey($comparison->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $force && ! $this->isSettleableStatus((string) $locked->status)) {
                return $locked;
            }

            $reservation = $this->findReservationForComparison($locked);
            if (! $reservation) {
                $finalCost = max(0, (int) ($options['actualCost'] ?? $this->calculateActualCost($locked)));

                $locked->final_credit_cost = $finalCost;
                $locked->credits_used = $finalCost;
                $this->mergeBillingSummary($locked, [
                    'state' => 'not_reserved',
                    'final_credit_cost' => $finalCost,
                    'settled_at' => now()->toIso8601String(),
                ]);
                $locked->save();

                return $locked;
            }

            // Idempotent return path after reservation finalization.
            if ($reservation->isCaptured() || $reservation->isReleased() || $reservation->isExpired()) {
                $finalCost = $reservation->isCaptured()
                    ? $this->capturedAmountFromReservation($reservation)
                    : 0;

                $locked->reserved_credit_amount = (int) $reservation->amount;
                $locked->final_credit_cost = $finalCost;
                $locked->credits_used = $finalCost;
                $this->persistBillingState(
                    comparison: $locked,
                    reservation: $reservation,
                    state: $reservation->status,
                    extra: [
                        'final_credit_cost' => $finalCost,
                        'settled_at' => now()->toIso8601String(),
                    ],
                    persist: false,
                );
                $locked->save();

                return $locked;
            }

            $actualCost = max(0, (int) ($options['actualCost'] ?? $this->calculateActualCost($locked)));
            $reservedAmount = max(0, (int) $reservation->amount);

            if ($actualCost <= 0) {
                $released = $this->reservations->release(
                    $reservation,
                    'draft_compare_failed',
                    [
                        'userId' => $userId,
                        'metadata' => [
                            'comparison_id' => (string) $locked->id,
                            'comparison_status' => (string) $locked->status,
                            'final_credit_cost' => 0,
                        ],
                    ]
                );

                $locked->reserved_credit_amount = $reservedAmount;
                $locked->final_credit_cost = 0;
                $locked->credits_used = 0;
                $this->persistBillingState(
                    comparison: $locked,
                    reservation: $released,
                    state: 'released',
                    extra: [
                        'final_credit_cost' => 0,
                        'released_reason' => 'draft_compare_failed',
                        'settled_at' => now()->toIso8601String(),
                    ],
                    persist: false,
                );
                $locked->save();

                return $locked;
            }

            $captureAmount = min($actualCost, $reservedAmount);
            $captured = $this->reservations->capture(
                $reservation,
                [
                    'userId' => $userId,
                    'captureAmount' => $captureAmount,
                    'metadata' => [
                        'comparison_id' => (string) $locked->id,
                        'comparison_status' => (string) $locked->status,
                        'actual_credit_cost' => $actualCost,
                        'reserved_credit_amount' => $reservedAmount,
                    ],
                ]
            );

            if ($actualCost > $reservedAmount) {
                Log::warning('Draft comparison actual credit cost exceeded reserved amount; capped at reserved amount.', [
                    'comparison_id' => (string) $locked->id,
                    'actual_cost' => $actualCost,
                    'reserved_amount' => $reservedAmount,
                ]);
            }

            $locked->reserved_credit_amount = $reservedAmount;
            $locked->final_credit_cost = $captureAmount;
            $locked->credits_used = $captureAmount;
            $this->persistBillingState(
                comparison: $locked,
                reservation: $captured,
                state: 'captured',
                extra: [
                    'final_credit_cost' => $captureAmount,
                    'actual_credit_cost' => $actualCost,
                    'unused_reserved_credits' => max(0, $reservedAmount - $captureAmount),
                    'settled_at' => now()->toIso8601String(),
                ],
                persist: false,
            );
            $locked->save();

            return $locked;
        });
    }

    public function refundComparison(
        DraftComparison $comparison,
        string $reason = 'draft_compare_cancelled',
        ?int $userId = null,
    ): DraftComparison {
        return DB::transaction(function () use ($comparison, $reason, $userId): DraftComparison {
            $locked = DraftComparison::query()
                ->whereKey($comparison->id)
                ->lockForUpdate()
                ->firstOrFail();

            $reservation = $this->findReservationForComparison($locked);
            if (! $reservation) {
                return $locked;
            }

            if ($reservation->isReleased() || $reservation->isExpired()) {
                return $locked;
            }

            if ($reservation->isCaptured()) {
                return $locked;
            }

            $released = $this->reservations->release(
                $reservation,
                $reason,
                [
                    'userId' => $userId,
                    'metadata' => [
                        'comparison_id' => (string) $locked->id,
                        'comparison_status' => (string) $locked->status,
                    ],
                ]
            );

            $locked->reserved_credit_amount = (int) $released->amount;
            $locked->final_credit_cost = 0;
            $locked->credits_used = 0;
            $this->persistBillingState(
                comparison: $locked,
                reservation: $released,
                state: 'released',
                extra: [
                    'final_credit_cost' => 0,
                    'released_reason' => $reason,
                    'settled_at' => now()->toIso8601String(),
                ],
                persist: false,
            );
            $locked->save();

            return $locked;
        });
    }

    private function calculateActualCost(DraftComparison $comparison): int
    {
        $variantCount = $comparison->variants()->count();

        if ($variantCount > 0) {
            return max(0, (int) $comparison->variants()
                ->where('status', \App\Models\DraftComparisonVariant::STATUS_COMPLETED)
                ->sum('credit_cost'));
        }

        $total = $comparison->items()
            ->where('status', 'generated')
            ->selectRaw('SUM(CASE WHEN charged_credits > 0 THEN charged_credits ELSE credit_cost END) as billed_total')
            ->value('billed_total');

        return max(0, (int) $total);
    }

    private function isSettleableStatus(string $status): bool
    {
        return in_array($status, [
            DraftComparison::STATUS_COMPLETED,
            DraftComparison::STATUS_PARTIALLY_FAILED,
            DraftComparison::STATUS_FAILED,
            'partially_completed',
            'completed',
            'failed',
            DraftComparison::STATUS_CANCELLED,
            'cancelled',
        ], true);
    }

    private function reservationIdempotencyKey(DraftComparison $comparison): string
    {
        return 'draft_compare:' . (string) $comparison->id . ':reserve';
    }

    private function findReservationForComparison(DraftComparison $comparison): ?CreditReservation
    {
        $key = trim((string) data_get($comparison->comparison_summary_json, 'billing.reservation_idempotency_key', ''));
        if ($key === '') {
            $key = $this->reservationIdempotencyKey($comparison);
        }

        return $this->reservations->findByIdempotencyKey($key);
    }

    private function capturedAmountFromReservation(CreditReservation $reservation): int
    {
        $fromMetadata = (int) Arr::get((array) $reservation->metadata, 'captured_amount', 0);
        if ($fromMetadata > 0) {
            return $fromMetadata;
        }

        if ($reservation->captureLedgerEntry) {
            return max(0, abs((int) $reservation->captureLedgerEntry->amount));
        }

        return max(0, (int) $reservation->amount);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function persistBillingState(
        DraftComparison $comparison,
        CreditReservation $reservation,
        string $state,
        array $extra = [],
        bool $persist = true,
    ): void {
        $comparison->reserved_credit_amount = (int) $reservation->amount;

        $billing = [
            'state' => $state,
            'reservation_id' => (string) $reservation->id,
            'reservation_idempotency_key' => (string) $reservation->idempotency_key,
            'reservation_status' => (string) $reservation->status,
            'reserved_credit_amount' => (int) $reservation->amount,
        ];

        $this->mergeBillingSummary($comparison, array_merge($billing, $extra));

        if ($persist) {
            $comparison->save();
        }
    }

    /**
     * @param array<string, mixed> $billingData
     */
    private function mergeBillingSummary(DraftComparison $comparison, array $billingData): void
    {
        $summary = is_array($comparison->comparison_summary_json) ? $comparison->comparison_summary_json : [];
        $existingBilling = is_array($summary['billing'] ?? null) ? $summary['billing'] : [];

        $summary['billing'] = array_filter(array_merge($existingBilling, $billingData), static fn (mixed $value): bool => $value !== null);

        $comparison->comparison_summary_json = $summary;
    }
}
