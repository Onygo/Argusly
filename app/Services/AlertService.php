<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\IntelligenceSignal;
use App\Models\SignalAlert;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AlertService
{
    public function triggerForSignal(IntelligenceSignal $signal): ?SignalAlert
    {
        $severity = $signal->severity ?: $signal->priority;

        if (! in_array($severity, ['high', 'critical'], true)) {
            return null;
        }

        $alert = SignalAlert::query()->updateOrCreate(
            [
                'account_id' => $signal->account_id,
                'brand_id' => $signal->brand_id,
                'intelligence_signal_id' => $signal->id,
            ],
            [
                'severity' => $severity,
                'status' => in_array($signal->status, ['dismissed', 'resolved'], true) ? 'resolved' : 'open',
                'title' => $signal->title,
                'body' => $signal->summary,
                'payload' => [
                    'signal_id' => $signal->id,
                    'signal_type' => $signal->type,
                    'category' => $signal->category,
                    'priority' => $signal->priority,
                    'severity' => $severity,
                ],
                'triggered_at' => $signal->detected_at ?? now(),
            ],
        );

        app(NotificationService::class)->notify(
            $signal->account,
            $signal->brand,
            'operational_alert',
            $alert->title,
            $alert->body ?: 'A high severity operational signal needs attention.',
            ['alert_id' => $alert->id, 'signal_id' => $signal->id, 'severity' => $alert->severity],
        );

        return $alert->refresh();
    }

    /**
     * @param  array{status?: string|null, severity?: string|null}  $filters
     * @return LengthAwarePaginator<int, SignalAlert>
     */
    public function paginatedForPlatform(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return SignalAlert::query()
            ->with(['account', 'brand', 'signal'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['severity'] ?? null, fn (Builder $query, string $severity) => $query->where('severity', $severity))
            ->latest('triggered_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function acknowledge(SignalAlert $alert, ?User $user = null): SignalAlert
    {
        $alert->acknowledge($user);

        return $alert->refresh();
    }

    public function resolve(SignalAlert $alert, ?User $user = null): SignalAlert
    {
        $alert->resolve($user);

        return $alert->refresh();
    }

    /**
     * @return array{open: int, critical: int, high: int, acknowledged: int}
     */
    public function statistics(?Account $account = null, ?Brand $brand = null): array
    {
        $query = SignalAlert::query()
            ->when($account, fn (Builder $scope) => $scope->where('account_id', $account->id))
            ->when($brand, fn (Builder $scope) => $scope->where('brand_id', $brand->id));

        return [
            'open' => (clone $query)->open()->count(),
            'critical' => (clone $query)->open()->where('severity', 'critical')->count(),
            'high' => (clone $query)->open()->where('severity', 'high')->count(),
            'acknowledged' => (clone $query)->where('status', 'acknowledged')->count(),
        ];
    }
}
