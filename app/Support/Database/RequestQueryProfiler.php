<?php

namespace App\Support\Database;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestQueryProfiler
{
    /**
     * @var array<int,array{sql:string,time:float}>
     */
    private array $queries = [];

    private function __construct(
        private readonly string $channel
    ) {
        DB::listen(function (QueryExecuted $query): void {
            $this->queries[] = [
                'sql' => $query->sql,
                'time' => (float) $query->time,
            ];
        });
    }

    public static function startIfEnabled(Request $request, string $channel): ?self
    {
        if (! app()->isLocal() || ! $request->boolean('debug_queries')) {
            return null;
        }

        return new self($channel);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function logSummary(array $context = []): void
    {
        $totalTime = array_sum(array_column($this->queries, 'time'));
        $slowest = collect($this->queries)
            ->sortByDesc('time')
            ->take(10)
            ->map(fn (array $query): array => [
                'time_ms' => round($query['time'], 2),
                'sql' => $query['sql'],
            ])
            ->values()
            ->all();

        Log::debug('query_profile.'.$this->channel, array_merge($context, [
            'query_count' => count($this->queries),
            'total_time_ms' => round($totalTime, 2),
            'slowest' => $slowest,
        ]));
    }
}
