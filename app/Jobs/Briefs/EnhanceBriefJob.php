<?php

namespace App\Jobs\Briefs;

use App\Actions\Briefs\EnhanceBriefAction;
use App\Models\Brief;
use App\Models\CreditReservation;
use App\Models\User;
use App\Services\CreditReservationService;
use App\Services\CreditWalletService;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EnhanceBriefJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public readonly string $briefId,
        public readonly string $runKey,
        public readonly bool $force = false,
        public readonly ?int $requestedBy = null,
    ) {
        $this->onQueue((string) config('brief_intelligence.queue', 'brief-intelligence'));
    }

    public function uniqueId(): string
    {
        return 'brief-intelligence:' . $this->briefId;
    }

    public function handle(
        EnhanceBriefAction $enhanceAction,
        FeatureGate $featureGate,
        CreditReservationService $reservations,
        CreditWalletService $wallets,
    ): void {
        $brief = Brief::query()->with('clientSite.workspace')->find($this->briefId);

        if (! $brief || ! $brief->clientSite?->workspace) {
            return;
        }

        $user = $this->resolveUser($brief);
        if (! $user) {
            $this->markFailure($brief, 'Unable to resolve user context for brief intelligence run.');

            return;
        }

        $this->markRuntime($brief, [
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'failure_reason' => null,
            'run_key' => $this->runKey,
            'force' => $this->force,
        ]);

        $reservation = null;
        $billing = $this->billingConfig($brief, $featureGate);

        try {
            if ($billing['enabled']) {
                $available = $wallets->getAvailableForClientSite((string) $brief->client_site_id);
                if ($available < $billing['credits_per_run']) {
                    $this->markFailure(
                        $brief,
                        sprintf('Insufficient credits for brief intelligence. Required %d, available %d.', $billing['credits_per_run'], $available)
                    );

                    return;
                }

                $reservation = $reservations->reserve(
                    clientSiteId: (string) $brief->client_site_id,
                    amount: $billing['credits_per_run'],
                    idempotencyKey: $this->reservationKey($brief),
                    purpose: 'brief_intelligence_enhance',
                    context: $brief,
                    options: [
                        'userId' => $user->id,
                        'metadata' => [
                            'brief_id' => (string) $brief->id,
                            'run_key' => $this->runKey,
                        ],
                    ],
                );
            }

            $result = $enhanceAction->execute($brief, $user, $this->force);

            if ($billing['enabled'] && $reservation instanceof CreditReservation && $reservation->isReserved()) {
                $reservations->capture($reservation, [
                    'userId' => $user->id,
                    'metadata' => [
                        'feature' => 'brief_intelligence',
                        'event' => 'enhance_succeeded',
                        'suggestions_created' => (int) ($result['suggestions_created'] ?? 0),
                    ],
                ]);
            }

            $this->markRuntime($brief->fresh(), [
                'status' => 'succeeded',
                'completed_at' => now()->toIso8601String(),
                'failure_reason' => null,
                'suggestions_created' => (int) ($result['suggestions_created'] ?? 0),
            ]);
        } catch (Throwable $exception) {
            if ($billing['enabled'] && $reservation instanceof CreditReservation && $reservation->isReserved()) {
                try {
                    $reservations->release($reservation, 'brief_intelligence_failed', [
                        'userId' => $user->id,
                        'failureCode' => 'brief_intelligence_failed',
                        'failureMessage' => $exception->getMessage(),
                    ]);
                } catch (Throwable) {
                    // Best effort release.
                }
            }

            $this->markFailure($brief->fresh(), $exception->getMessage());

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $brief = Brief::query()->find($this->briefId);

        if (! $brief) {
            return;
        }

        $this->markFailure($brief, $exception->getMessage());
    }

    private function resolveUser(Brief $brief): ?User
    {
        if ($this->requestedBy) {
            $user = User::query()->find($this->requestedBy);
            if ($user) {
                return $user;
            }
        }

        if ($brief->created_by_user_id) {
            $user = User::query()->find((int) $brief->created_by_user_id);
            if ($user) {
                return $user;
            }
        }

        return User::query()
            ->where('organization_id', $brief->clientSite?->workspace?->organization_id)
            ->whereIn('role', ['owner', 'admin'])
            ->orderBy('id')
            ->first();
    }

    private function reservationKey(Brief $brief): string
    {
        return sprintf('brief_intelligence:%s:%s', (string) $brief->id, $this->runKey);
    }

    /**
     * @return array{enabled:bool,credits_per_run:int}
     */
    private function billingConfig(Brief $brief, FeatureGate $featureGate): array
    {
        $workspace = $brief->clientSite?->workspace;

        $enabled = $this->toBool(
            $featureGate->value(
                $workspace,
                'brief_intelligence_billing_enabled',
                (bool) config('brief_intelligence.billing.enabled_by_default', false)
            ),
            (bool) config('brief_intelligence.billing.enabled_by_default', false)
        );

        $creditsPerRun = max(
            0,
            (int) $featureGate->value(
                $workspace,
                'brief_intelligence_credits_per_run',
                (int) config('brief_intelligence.billing.credits_per_run', 0)
            )
        );

        return [
            'enabled' => $enabled && $creditsPerRun > 0 && trim((string) ($brief->client_site_id ?? '')) !== '',
            'credits_per_run' => $creditsPerRun,
        ];
    }

    /**
     * @param array<string,mixed> $runtime
     */
    private function markRuntime(Brief $brief, array $runtime): void
    {
        $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
        $intelligence = is_array($refs['brief_intelligence'] ?? null) ? $refs['brief_intelligence'] : [];

        $intelligence['runtime'] = array_replace_recursive((array) ($intelligence['runtime'] ?? []), $runtime);
        $refs['brief_intelligence'] = $intelligence;

        $brief->update([
            'client_refs' => $refs,
        ]);
    }

    private function markFailure(Brief $brief, string $reason): void
    {
        $this->markRuntime($brief, [
            'status' => 'failed',
            'failed_at' => now()->toIso8601String(),
            'failure_reason' => mb_substr($reason, 0, 5000),
        ]);
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
    }
}
