<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Account;
use App\Models\Brand;
use App\Models\CreditBalance;
use App\Models\CreditTransaction;
use App\Models\CreditUsageStat;
use App\Models\User;
use App\Services\Signals\SignalManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreditService
{
    public function __construct(private readonly CreditCostResolver $costs) {}

    public function balance(Account $account): int
    {
        return (int) CreditBalance::query()
            ->where('account_id', $account->id)
            ->value('balance');
    }

    public function cost(string $key): int
    {
        return $this->costs->resolveCost($key)['cost'];
    }

    public function grant(Account $account, int $credits, ?User $user = null, string $description = 'Credit grant', ?array $metadata = null): CreditTransaction
    {
        return $this->adjust($account, $credits, 'grant', $description, $user, null, $metadata);
    }

    public function consume(
        Account $account,
        User $user,
        string $costKey,
        string $description,
        ?Model $subject = null,
        ?array $metadata = null,
    ): CreditTransaction {
        $brand = $this->brandFrom($account, $subject, $metadata);
        $resolved = $brand
            ? $this->costs->resolveCostForBrand($account, $brand, $costKey, $metadata ?? [])
            : $this->costs->resolveCostForAccount($account, $costKey, $metadata ?? []);
        $credits = $resolved['cost'];

        return DB::transaction(function () use ($account, $user, $costKey, $description, $subject, $metadata, $credits, $resolved, $brand): CreditTransaction {
            $balance = $this->lockedBalance($account);

            if ($balance->balance < $credits) {
                throw new InsufficientCreditsException($credits, $balance->balance);
            }

            $balance->balance -= $credits;
            $balance->save();

            $transaction = CreditTransaction::query()->create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'amount' => -$credits,
                'balance_after' => $balance->balance,
                'type' => $resolved['code'],
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'metadata' => [
                    ...($metadata ?? []),
                    'requested_cost_code' => $costKey,
                    'catalog_code' => $resolved['code'],
                    'catalog_cost_source' => $resolved['source'],
                    'credit_cost_catalog_id' => $resolved['catalog']->id,
                ],
            ]);

            $this->recordUsage($account, $brand, $resolved['code'], $credits);
            app(SignalManager::class)->produce($transaction);
            app(DomainEventService::class)->recordForSubject('CreditsConsumed', $transaction, $user, [
                'amount' => abs($transaction->amount),
                'balance_after' => $transaction->balance_after,
                'catalog_code' => $resolved['code'],
                'requested_cost_code' => $costKey,
                'source' => $resolved['source'],
            ], $transaction->created_at, dispatch: false);

            if ($transaction->balance_after <= 100) {
                app(DomainEventService::class)->recordForSubject('CreditsLow', $transaction, $user, [
                    'amount' => $transaction->amount,
                    'balance_after' => $transaction->balance_after,
                    'type' => $transaction->type,
                    'description' => $transaction->description,
                ], $transaction->created_at);
                app(DomainEventService::class)->recordForSubject('LowCreditsDetected', $transaction, $user, [
                    'balance_after' => $transaction->balance_after,
                    'catalog_code' => $resolved['code'],
                ], $transaction->created_at, dispatch: false);
            }

            return $transaction;
        });
    }

    public function consumeForAccount(
        Account $account,
        string $costKey,
        string $description,
        ?Model $subject = null,
        ?array $metadata = null,
    ): CreditTransaction {
        $brand = $this->brandFrom($account, $subject, $metadata);
        $resolved = $brand
            ? $this->costs->resolveCostForBrand($account, $brand, $costKey, $metadata ?? [])
            : $this->costs->resolveCostForAccount($account, $costKey, $metadata ?? []);
        $credits = $resolved['cost'];

        return DB::transaction(function () use ($account, $costKey, $description, $subject, $metadata, $credits, $resolved, $brand): CreditTransaction {
            $balance = $this->lockedBalance($account);

            if ($balance->balance < $credits) {
                throw new InsufficientCreditsException($credits, $balance->balance);
            }

            $balance->balance -= $credits;
            $balance->save();

            $transaction = CreditTransaction::query()->create([
                'account_id' => $account->id,
                'user_id' => null,
                'amount' => -$credits,
                'balance_after' => $balance->balance,
                'type' => $resolved['code'],
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'metadata' => [
                    ...($metadata ?? []),
                    'requested_cost_code' => $costKey,
                    'catalog_code' => $resolved['code'],
                    'catalog_cost_source' => $resolved['source'],
                    'credit_cost_catalog_id' => $resolved['catalog']->id,
                ],
            ]);

            $this->recordUsage($account, $brand, $resolved['code'], $credits);
            app(SignalManager::class)->produce($transaction);
            app(DomainEventService::class)->recordForSubject('CreditsConsumed', $transaction, null, [
                'amount' => abs($transaction->amount),
                'balance_after' => $transaction->balance_after,
                'catalog_code' => $resolved['code'],
                'requested_cost_code' => $costKey,
                'source' => $resolved['source'],
            ], $transaction->created_at, dispatch: false);

            if ($transaction->balance_after <= 100) {
                app(DomainEventService::class)->recordForSubject('CreditsLow', $transaction, null, [
                    'amount' => $transaction->amount,
                    'balance_after' => $transaction->balance_after,
                    'type' => $transaction->type,
                    'description' => $transaction->description,
                ], $transaction->created_at);
                app(DomainEventService::class)->recordForSubject('LowCreditsDetected', $transaction, null, [
                    'balance_after' => $transaction->balance_after,
                    'catalog_code' => $resolved['code'],
                ], $transaction->created_at, dispatch: false);
            }

            return $transaction;
        });
    }

    private function adjust(
        Account $account,
        int $amount,
        string $type,
        string $description,
        ?User $user = null,
        ?Model $subject = null,
        ?array $metadata = null,
    ): CreditTransaction {
        return DB::transaction(function () use ($account, $amount, $type, $description, $user, $subject, $metadata): CreditTransaction {
            $balance = $this->lockedBalance($account);
            $balance->balance += $amount;
            $balance->save();

            $transaction = CreditTransaction::query()->create([
                'account_id' => $account->id,
                'user_id' => $user?->id,
                'amount' => $amount,
                'balance_after' => $balance->balance,
                'type' => $type,
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'metadata' => $metadata,
            ]);

            if ($amount > 0 && $type === 'refund') {
                app(DomainEventService::class)->recordForSubject('CreditsRefunded', $transaction, $user, [
                    'amount' => $amount,
                    'balance_after' => $transaction->balance_after,
                    'description' => $description,
                ], $transaction->created_at, dispatch: false);
            }

            return $transaction;
        });
    }

    public function refund(Account $account, int $credits, ?User $user = null, string $description = 'Credit refund', ?array $metadata = null): CreditTransaction
    {
        return $this->adjust($account, $credits, 'refund', $description, $user, null, $metadata);
    }

    private function brandFrom(Account $account, ?Model $subject, ?array $metadata): ?Brand
    {
        $brandId = $metadata['brand_id'] ?? null;

        if ($brandId === null && $subject && isset($subject->brand_id)) {
            $brandId = $subject->brand_id;
        }

        if (! is_numeric($brandId)) {
            return null;
        }

        return Brand::query()
            ->where('account_id', $account->id)
            ->whereKey((int) $brandId)
            ->first();
    }

    private function recordUsage(Account $account, ?Brand $brand, string $catalogCode, int $credits): void
    {
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->copy()->endOfMonth();

        $stat = CreditUsageStat::query()->firstOrCreate(
            [
                'account_id' => $account->id,
                'brand_id' => $brand?->id,
                'catalog_code' => $catalogCode,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'credits_used' => 0,
                'executions' => 0,
            ],
        );

        $stat->forceFill([
            'credits_used' => $stat->credits_used + $credits,
            'executions' => $stat->executions + 1,
        ])->save();
    }

    private function lockedBalance(Account $account): CreditBalance
    {
        $balance = CreditBalance::query()
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            return $balance;
        }

        return CreditBalance::query()->create([
            'account_id' => $account->id,
            'balance' => 0,
        ]);
    }
}
