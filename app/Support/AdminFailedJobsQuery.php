<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

class AdminFailedJobsQuery
{
    /**
     * @return array{range:string,from:?string,to:?string,job_class:string,queue:string,org_site:string}
     */
    public static function resolveFilters(Request $request): array
    {
        $validated = $request->validate([
            'range' => ['nullable', 'in:24h,7d,30d,custom'],
            'from' => ['nullable', 'string', 'max:255'],
            'to' => ['nullable', 'string', 'max:255'],
            'job_class' => ['nullable', 'string', 'max:255'],
            'queue' => ['nullable', 'string', 'max:255'],
            'org_site' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'range' => (string) ($validated['range'] ?? '24h'),
            'from' => isset($validated['from']) ? trim((string) $validated['from']) : null,
            'to' => isset($validated['to']) ? trim((string) $validated['to']) : null,
            'job_class' => trim((string) ($validated['job_class'] ?? '')),
            'queue' => trim((string) ($validated['queue'] ?? '')),
            'org_site' => trim((string) ($validated['org_site'] ?? '')),
        ];
    }

    /**
     * @param array{range:string,from:?string,to:?string,job_class:string,queue:string,org_site:string} $filters
     * @return array{0:Carbon,1:Carbon}
     */
    public static function resolveDateRange(Request $request, array $filters): array
    {
        $now = now();

        $fromInputFilled = $request->filled('from');
        $toInputFilled = $request->filled('to');

        if ($fromInputFilled || $toInputFilled) {
            $from = self::parseDateTime($filters['from'] ?? null)?->startOfDay() ?? $now->copy()->subDay();
            $to = self::parseDateTime($filters['to'] ?? null)?->endOfDay() ?? $now;

            return [$from, $to];
        }

        return match ($filters['range']) {
            '7d' => [$now->copy()->subDays(7), $now],
            '30d' => [$now->copy()->subDays(30), $now],
            default => [$now->copy()->subDay(), $now],
        };
    }

    /**
     * @param array{range:string,from:?string,to:?string,job_class:string,queue:string,org_site:string} $filters
     */
    public static function applyFilters(
        Builder $query,
        Request $request,
        array $filters,
        Carbon $from,
        Carbon $to
    ): Builder {
        $query->whereBetween('failed_at', [$from, $to]);

        if ($request->filled('queue')) {
            $query->where('queue', $filters['queue']);
        }

        if ($request->filled('job_class')) {
            $needle = '%' . $filters['job_class'] . '%';
            $query->where(function (Builder $builder) use ($needle): void {
                $builder->where('payload', 'like', $needle)
                    ->orWhere('queue', 'like', $needle)
                    ->orWhere('connection', 'like', $needle);
            });
        }

        if ($request->filled('org_site')) {
            $needle = '%' . $filters['org_site'] . '%';
            $query->where(function (Builder $builder) use ($needle): void {
                $builder->where('payload', 'like', $needle)
                    ->orWhere('exception', 'like', $needle);
            });
        }

        return $query;
    }

    private static function parseDateTime(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
