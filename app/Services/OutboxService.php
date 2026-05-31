<?php

namespace App\Services;

use App\Jobs\ProcessOutboxMessageJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\OutboxMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class OutboxService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        Account $account,
        ?Brand $brand,
        string $type,
        array $payload,
        mixed $availableAt = null,
        bool $dispatch = true,
    ): OutboxMessage {
        $this->validate($account, $brand, $type, $payload);

        $message = DB::transaction(function () use ($account, $brand, $type, $payload, $availableAt): OutboxMessage {
            $idempotencyKey = $payload['idempotency_key'] ?? null;

            if ($idempotencyKey !== null) {
                $existing = OutboxMessage::query()
                    ->where('account_id', $account->id)
                    ->where('type', $type)
                    ->where('payload->idempotency_key', $idempotencyKey)
                    ->whereNotIn('status', ['failed', 'cancelled'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            return OutboxMessage::query()->create([
                'account_id' => $account->id,
                'brand_id' => $brand?->id,
                'type' => $type,
                'status' => 'pending',
                'payload' => $payload,
                'available_at' => $availableAt,
            ]);
        });

        if ($dispatch && $message->status === 'pending') {
            ProcessOutboxMessageJob::dispatch($message->id);
        }

        return $message;
    }

    public function process(OutboxMessage $message): OutboxMessage
    {
        return DB::transaction(function () use ($message): OutboxMessage {
            $message = OutboxMessage::query()
                ->whereKey($message->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($message->status, ['completed', 'cancelled'], true)) {
                return $message;
            }

            if ($message->status !== 'pending') {
                return $message;
            }

            if ($message->available_at !== null && $message->available_at->isFuture()) {
                return $message;
            }

            $message->forceFill([
                'status' => 'processing',
                'attempts' => $message->attempts + 1,
                'last_attempted_at' => now(),
                'error' => null,
            ])->save();

            try {
                // External connector execution is intentionally not implemented yet.
                $message->forceFill([
                    'status' => 'completed',
                    'processed_at' => now(),
                    'error' => null,
                ])->save();
            } catch (Throwable $exception) {
                $message->forceFill([
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ])->save();
            }

            return $message->refresh();
        });
    }

    public function cancel(OutboxMessage $message, ?string $reason = null): OutboxMessage
    {
        if (in_array($message->status, ['completed', 'cancelled'], true)) {
            return $message;
        }

        $message->forceFill([
            'status' => 'cancelled',
            'error' => $reason,
        ])->save();

        return $message->refresh();
    }

    /**
     * @param  array{status?: string|null, type?: string|null}  $filters
     * @return LengthAwarePaginator<int, OutboxMessage>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Builder<OutboxMessage>
     */
    public function tenantQuery(Account $account, ?Brand $brand = null): Builder
    {
        return OutboxMessage::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validate(Account $account, ?Brand $brand, string $type, array $payload): void
    {
        if (! in_array($type, OutboxMessage::TYPES, true)) {
            throw new InvalidArgumentException("Invalid outbox message type [{$type}].");
        }

        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Outbox message brand must belong to the same account.');
        }

        if ($payload === []) {
            throw new InvalidArgumentException('Outbox message payload is required.');
        }
    }
}
