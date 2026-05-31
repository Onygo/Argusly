<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Account;
use App\Models\CreditBalance;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Signals\SignalManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreditService
{
    public function balance(Account $account): int
    {
        return (int) CreditBalance::query()
            ->where('account_id', $account->id)
            ->value('balance');
    }

    public function cost(string $key): int
    {
        return (int) config("credits.costs.{$key}", 0);
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
        $credits = $this->cost($costKey);

        return DB::transaction(function () use ($account, $user, $costKey, $description, $subject, $metadata, $credits): CreditTransaction {
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
                'type' => $costKey,
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'metadata' => $metadata,
            ]);

            app(SignalManager::class)->produce($transaction);

            if ($transaction->balance_after <= 100) {
                app(DomainEventService::class)->recordForSubject('CreditsLow', $transaction, $user, [
                    'amount' => $transaction->amount,
                    'balance_after' => $transaction->balance_after,
                    'type' => $transaction->type,
                    'description' => $transaction->description,
                ], $transaction->created_at);
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
        $credits = $this->cost($costKey);

        return DB::transaction(function () use ($account, $costKey, $description, $subject, $metadata, $credits): CreditTransaction {
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
                'type' => $costKey,
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'metadata' => $metadata,
            ]);

            app(SignalManager::class)->produce($transaction);

            if ($transaction->balance_after <= 100) {
                app(DomainEventService::class)->recordForSubject('CreditsLow', $transaction, null, [
                    'amount' => $transaction->amount,
                    'balance_after' => $transaction->balance_after,
                    'type' => $transaction->type,
                    'description' => $transaction->description,
                ], $transaction->created_at);
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

            return CreditTransaction::query()->create([
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
        });
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
