<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class ActivityState
{
    private const CACHE_KEY = 'argusly_connector.activity';

    /**
     * @param array<string, mixed> $extra
     */
    public function record(string $type, array $extra = []): void
    {
        $state = $this->get();
        $now = Carbon::now()->toIso8601String();

        $state['last_seen_at'] = $now;
        $state['last_' . $type . '_at'] = $now;
        $state['recent_events_count_24h'] = max(0, (int) ($state['recent_events_count_24h'] ?? 0)) + 1;

        foreach ($extra as $key => $value) {
            if ($value !== null && $value !== '') {
                $state[$key] = $value;
            }
        }

        Cache::put(self::CACHE_KEY, $state, now()->addDay());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $state = Cache::get(self::CACHE_KEY);

        return is_array($state) ? $state : [];
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
