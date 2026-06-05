<?php

namespace App\Services\DraftDelivery;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Manages distributed locks for content delivery operations.
 *
 * Prevents concurrent delivery attempts for the same content + destination pair,
 * ensuring idempotent delivery behavior and preventing duplicate remote posts.
 */
class DeliveryLockService
{
    // Lock timeout in seconds (should exceed typical delivery time + retries)
    private const DEFAULT_LOCK_TIMEOUT = 300; // 5 minutes

    // Wait timeout when trying to acquire lock
    private const DEFAULT_WAIT_TIMEOUT = 10; // 10 seconds

    /**
     * Attempt to acquire a delivery lock for the given content + destination.
     *
     * @param  string  $contentId  The content being delivered
     * @param  string  $destinationId  The destination (client_site_id or destination_id)
     * @param  int  $timeout  Lock timeout in seconds
     * @return Lock|null Returns the lock if acquired, null if lock unavailable
     */
    public function acquireDeliveryLock(
        string $contentId,
        string $destinationId,
        int $timeout = self::DEFAULT_LOCK_TIMEOUT
    ): ?Lock {
        $lockKey = $this->buildLockKey($contentId, $destinationId);
        $lock = Cache::lock($lockKey, $timeout);

        if ($lock->get()) {
            Log::channel('delivery')->info('Delivery lock acquired', [
                'lock_key' => $lockKey,
                'content_id' => $contentId,
                'destination_id' => $destinationId,
                'timeout_seconds' => $timeout,
            ]);

            return $lock;
        }

        Log::channel('delivery')->warning('Failed to acquire delivery lock', [
            'lock_key' => $lockKey,
            'content_id' => $contentId,
            'destination_id' => $destinationId,
            'reason' => 'Lock already held by another process',
        ]);

        return null;
    }

    /**
     * Attempt to acquire a delivery lock, blocking until available or timeout.
     *
     * @param  string  $contentId  The content being delivered
     * @param  string  $destinationId  The destination (client_site_id or destination_id)
     * @param  int  $lockTimeout  Lock timeout in seconds
     * @param  int  $waitTimeout  How long to wait for lock acquisition
     * @return Lock|null Returns the lock if acquired, null if wait timed out
     */
    public function acquireDeliveryLockBlocking(
        string $contentId,
        string $destinationId,
        int $lockTimeout = self::DEFAULT_LOCK_TIMEOUT,
        int $waitTimeout = self::DEFAULT_WAIT_TIMEOUT
    ): ?Lock {
        $lockKey = $this->buildLockKey($contentId, $destinationId);
        $lock = Cache::lock($lockKey, $lockTimeout);

        $acquired = $lock->block($waitTimeout);

        if ($acquired) {
            Log::channel('delivery')->info('Delivery lock acquired (blocking)', [
                'lock_key' => $lockKey,
                'content_id' => $contentId,
                'destination_id' => $destinationId,
                'timeout_seconds' => $lockTimeout,
            ]);

            return $lock;
        }

        Log::channel('delivery')->warning('Failed to acquire delivery lock (timeout)', [
            'lock_key' => $lockKey,
            'content_id' => $contentId,
            'destination_id' => $destinationId,
            'wait_timeout_seconds' => $waitTimeout,
        ]);

        return null;
    }

    /**
     * Release a delivery lock.
     */
    public function releaseLock(Lock $lock): void
    {
        $lock->release();
    }

    /**
     * Execute a callback with a delivery lock.
     * The lock is automatically released when the callback completes or throws.
     *
     * @template T
     *
     * @param  string  $contentId
     * @param  string  $destinationId
     * @param  callable(): T  $callback
     * @param  int  $timeout
     * @return array{acquired: bool, result: T|null}
     */
    public function withDeliveryLock(
        string $contentId,
        string $destinationId,
        callable $callback,
        int $timeout = self::DEFAULT_LOCK_TIMEOUT
    ): array {
        $lock = $this->acquireDeliveryLock($contentId, $destinationId, $timeout);

        if (! $lock) {
            return [
                'acquired' => false,
                'result' => null,
            ];
        }

        try {
            $result = $callback();

            return [
                'acquired' => true,
                'result' => $result,
            ];
        } finally {
            $this->releaseLock($lock);

            Log::channel('delivery')->debug('Delivery lock released', [
                'lock_key' => $this->buildLockKey($contentId, $destinationId),
                'content_id' => $contentId,
                'destination_id' => $destinationId,
            ]);
        }
    }

    /**
     * Check if a delivery lock is currently held (without acquiring it).
     */
    public function isLocked(string $contentId, string $destinationId): bool
    {
        $lockKey = $this->buildLockKey($contentId, $destinationId);

        // Try to acquire and immediately release - if we can't, it's locked
        $lock = Cache::lock($lockKey, 1);
        $acquired = $lock->get();

        if ($acquired) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * Force release a delivery lock (use with caution - for recovery scenarios only).
     */
    public function forceRelease(string $contentId, string $destinationId): void
    {
        $lockKey = $this->buildLockKey($contentId, $destinationId);
        Cache::lock($lockKey)->forceRelease();

        Log::channel('delivery')->warning('Delivery lock force released', [
            'lock_key' => $lockKey,
            'content_id' => $contentId,
            'destination_id' => $destinationId,
        ]);
    }

    /**
     * Build the cache key for a delivery lock.
     */
    private function buildLockKey(string $contentId, string $destinationId): string
    {
        return "delivery_lock:{$contentId}:{$destinationId}";
    }
}
