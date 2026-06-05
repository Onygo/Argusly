<?php

namespace App\Services;

use App\Models\Source;
use App\Models\SourceSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SourceHealthService
{
    /**
     * @return array{healthy: int, warning: int, critical: int, stale: int, recent_failures: int, sources: Collection<int, array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $sources = Source::query()
            ->with(['brand', 'syncs' => fn ($query) => $query->latest()->limit(1)])
            ->withCount([
                'syncs as failed_syncs_count' => fn (Builder $query) => $query->where('status', 'failed'),
            ])
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(function (Source $source): array {
                $latest = $source->syncs->first();
                $status = $this->statusFor($source, $latest);

                return [
                    'source' => $source,
                    'sync' => $latest,
                    'status' => $status,
                    'detail' => $latest
                        ? "Last sync {$latest->status} at ".($latest->completed_at?->toDateTimeString() ?? $latest->created_at?->toDateTimeString())
                        : 'No sync history.',
                ];
            });

        return [
            'healthy' => $sources->where('status', 'healthy')->count(),
            'warning' => $sources->where('status', 'warning')->count(),
            'critical' => $sources->where('status', 'critical')->count(),
            'stale' => $sources->filter(fn (array $item): bool => $item['sync'] instanceof SourceSync && $item['sync']->completed_at?->lt(now()->subDays(7)))->count(),
            'recent_failures' => SourceSync::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count(),
            'sources' => $sources,
        ];
    }

    private function statusFor(Source $source, ?SourceSync $sync): string
    {
        if ($source->status !== 'active') {
            return 'warning';
        }

        if (! $sync) {
            return 'warning';
        }

        if ($sync->status === 'failed') {
            return 'critical';
        }

        if ($sync->completed_at?->lt(now()->subDays(7))) {
            return 'warning';
        }

        return 'healthy';
    }
}
