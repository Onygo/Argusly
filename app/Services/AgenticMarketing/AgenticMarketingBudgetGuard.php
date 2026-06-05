<?php

namespace App\Services\AgenticMarketing;

use App\Exceptions\InsufficientCreditsException;
use App\Models\AgenticMarketingAction;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\CreditReservation;
use App\Models\User;
use App\Services\CreditReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AgenticMarketingBudgetGuard
{
    public function __construct(
        private readonly CreditReservationService $reservations,
    ) {
    }

    public function reserveBeforeExecution(AgenticMarketingAction $action, ?User $actor = null, ?string $claimId = null): ?CreditReservation
    {
        try {
            return DB::transaction(function () use ($action, $actor, $claimId): ?CreditReservation {
                $credits = $this->estimatedCredits($action);

                if ($credits <= 0) {
                    $action->forceFill([
                        'credit_status' => 'skipped',
                        'budget_checked_at' => now(),
                        'credit_error_message' => null,
                    ])->save();

                    return null;
                }

                $this->assertObjectiveBudgetAllows($action, $credits);
                $siteId = $this->resolveClientSiteId($action);

                if (! $siteId) {
                    $message = 'A connected site credit wallet is required before executing this Agentic Marketing action.';
                    $this->markCreditFailure($action, $message);
                    throw new RuntimeException($message);
                }

                try {
                    $reservation = $this->reservations->reserve(
                        clientSiteId: $siteId,
                        amount: $credits,
                        idempotencyKey: $this->reservationKey($action, $claimId),
                        purpose: 'agentic_marketing:' . (string) $action->action_type,
                        context: $action,
                        options: [
                            'userId' => $actor?->id,
                            'ttlMinutes' => 60,
                            'metadata' => [
                                'agentic_marketing_action_id' => (string) $action->id,
                                'objective_id' => (string) $action->objective_id,
                                'action_type' => (string) $action->action_type,
                                'execution_claim_id' => $claimId,
                            ],
                        ],
                    );
                } catch (InsufficientCreditsException $exception) {
                    $message = sprintf(
                        'Insufficient credits for Agentic Marketing action. Required %d credits, available %d.',
                        $exception->required,
                        $exception->available,
                    );
                    $this->markCreditFailure($action, $message);
                    throw new RuntimeException($message, previous: $exception);
                }

                $action->forceFill([
                    'credit_reservation_id' => (string) $reservation->id,
                    'credits_reserved' => (int) $reservation->amount,
                    'credits_captured' => null,
                    'credit_status' => CreditReservation::STATUS_RESERVED,
                    'credit_error_message' => null,
                    'budget_checked_at' => now(),
                    'budget_exceeded_at' => null,
                ])->save();

                return $reservation;
            });
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            AgenticMarketingAction::query()->whereKey($action->id)->update([
                'credit_status' => str_contains($message, 'monthly budget exceeded') ? 'budget_exceeded' : 'failed',
                'credit_error_message' => $message,
                'budget_checked_at' => now(),
                'budget_exceeded_at' => str_contains($message, 'monthly budget exceeded') ? now() : $action->budget_exceeded_at,
                'updated_at' => now(),
            ]);
            $action->refresh();

            throw $exception;
        }
    }

    public function captureAfterExecution(AgenticMarketingAction $action, ?CreditReservation $reservation, ?User $actor = null): void
    {
        if (! $reservation) {
            return;
        }

        $captured = $this->reservations->capture($reservation, [
            'userId' => $actor?->id,
            'captureAmount' => (int) $reservation->amount,
            'metadata' => [
                'agentic_marketing_action_id' => (string) $action->id,
                'action_type' => (string) $action->action_type,
            ],
        ]);

        $action->forceFill([
            'credit_reservation_id' => (string) $captured->id,
            'credits_reserved' => (int) $captured->amount,
            'credits_captured' => (int) data_get($captured->metadata ?? [], 'captured_amount', $captured->amount),
            'credit_status' => CreditReservation::STATUS_CAPTURED,
            'credit_error_message' => null,
        ])->save();
    }

    public function releaseAfterFailure(AgenticMarketingAction $action, ?CreditReservation $reservation, string $message, ?User $actor = null): void
    {
        if (! $reservation) {
            return;
        }

        $released = $this->reservations->release($reservation, 'agentic_marketing_failed', [
            'userId' => $actor?->id,
            'failureCode' => 'agentic_marketing_execution_failed',
            'failureMessage' => $message,
            'metadata' => [
                'agentic_marketing_action_id' => (string) $action->id,
                'action_type' => (string) $action->action_type,
            ],
        ]);

        if ($released->isReleased()) {
            $action->forceFill([
                'credit_status' => CreditReservation::STATUS_RELEASED,
                'credit_error_message' => $message,
            ])->save();
        }
    }

    private function assertObjectiveBudgetAllows(AgenticMarketingAction $action, int $credits): void
    {
        $objective = $action->objective()->lockForUpdate()->first();
        $budget = $objective?->monthly_credit_budget;

        if ($budget === null) {
            $action->forceFill([
                'budget_checked_at' => now(),
                'budget_exceeded_at' => null,
            ])->save();

            return;
        }

        $monthStart = now()->startOfMonth();
        $committed = (int) AgenticMarketingAction::query()
            ->where('objective_id', $action->objective_id)
            ->whereKeyNot($action->id)
            ->where('created_at', '>=', $monthStart)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('credits_captured')
                    ->orWhereNotNull('credits_reserved');
            })
            ->sum(DB::raw('COALESCE(credits_captured, credits_reserved, 0)'));

        if (($committed + $credits) > (int) $budget) {
            $message = sprintf(
                'Agentic Marketing monthly budget exceeded. Budget %d credits, already committed %d, required %d.',
                (int) $budget,
                $committed,
                $credits,
            );
            $action->forceFill([
                'credit_status' => 'budget_exceeded',
                'credit_error_message' => $message,
                'budget_checked_at' => now(),
                'budget_exceeded_at' => now(),
            ])->save();

            throw new RuntimeException($message);
        }

        $action->forceFill([
            'budget_checked_at' => now(),
            'budget_exceeded_at' => null,
            'credit_error_message' => null,
        ])->save();
    }

    private function resolveClientSiteId(AgenticMarketingAction $action): ?string
    {
        $action->loadMissing(['objective', 'content', 'draft']);

        $siteId = (string) (
            data_get($action->payload ?? [], 'client_site_id')
            ?: data_get($action->payload ?? [], 'site_id')
            ?: $action->content?->client_site_id
            ?: $action->draft?->client_site_id
            ?: $action->objective?->client_site_id
            ?: ''
        );

        if ($siteId !== '') {
            return $siteId;
        }

        $contentId = (string) ($action->content_id ?: data_get($action->payload ?? [], 'content_id', ''));
        if ($contentId !== '') {
            $contentSiteId = Content::query()->whereKey($contentId)->value('client_site_id');
            if ($contentSiteId) {
                return (string) $contentSiteId;
            }
        }

        $workspaceId = (string) ($action->objective?->workspace_id ?: data_get($action->payload ?? [], 'workspace_id', ''));
        if ($workspaceId !== '') {
            return ClientSite::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->orderBy('created_at')
                ->value('id');
        }

        return null;
    }

    private function estimatedCredits(AgenticMarketingAction $action): int
    {
        return max(0, (int) ($action->estimated_credits ?? data_get($action->payload ?? [], 'planning.estimated_credits', 0)));
    }

    private function reservationKey(AgenticMarketingAction $action, ?string $claimId): string
    {
        return 'agentic-marketing-action:' . (string) $action->id . ':claim:' . ($claimId ?: $action->execution_claim_id ?: Str::uuid());
    }

    private function markCreditFailure(AgenticMarketingAction $action, string $message): void
    {
        $action->forceFill([
            'credit_status' => 'failed',
            'credit_error_message' => $message,
            'budget_checked_at' => now(),
        ])->save();
    }
}
