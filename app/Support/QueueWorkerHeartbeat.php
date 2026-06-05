<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class QueueWorkerHeartbeat
{
    public const CACHE_KEY = 'pl:queue:worker_heartbeat';
    public const TTL_MINUTES = 5;

    public static function storeName(): string
    {
        return (string) config('cache.default', 'file');
    }

    public static function key(): string
    {
        return self::CACHE_KEY;
    }

    public static function touch(?int $timestamp = null): void
    {
        $value = $timestamp ?? now()->timestamp;

        Cache::store(self::storeName())->put(
            self::key(),
            $value,
            now()->addMinutes(self::TTL_MINUTES)
        );
    }

    public static function timestamp(): ?int
    {
        $value = Cache::store(self::storeName())->get(self::key());

        return is_numeric($value) ? (int) $value : null;
    }

    public static function isAlive(?int $timestamp = null, int $thresholdSeconds = 120): bool
    {
        $resolved = $timestamp ?? self::timestamp();

        return $resolved !== null && (now()->timestamp - $resolved) <= $thresholdSeconds;
    }

    public static function lastHeartbeatAt(?int $timestamp = null): ?Carbon
    {
        $resolved = $timestamp ?? self::timestamp();

        return $resolved !== null ? Carbon::createFromTimestamp($resolved) : null;
    }
}
