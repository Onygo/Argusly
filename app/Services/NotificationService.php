<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\DomainEvent;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class NotificationService
{
    /**
     * @return Collection<int, NotificationEvent>
     */
    public function eventsForUser(User $user, Account $account, ?Brand $brand = null, int $limit = 25): Collection
    {
        return NotificationEvent::query()
            ->where('user_id', $user->id)
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)))
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function unreadCount(User $user, Account $account, ?Brand $brand = null): int
    {
        return NotificationEvent::query()
            ->where('user_id', $user->id)
            ->where('account_id', $account->id)
            ->when($brand !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)))
            ->unread()
            ->count();
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function preferenceMatrix(User $user, Account $account, ?Brand $brand = null): array
    {
        return collect(NotificationPreference::TYPES)
            ->mapWithKeys(fn (string $type) => [
                $type => collect(NotificationPreference::CHANNELS)
                    ->mapWithKeys(fn (string $channel) => [$channel => $this->enabled($user, $account, $brand, $type, $channel)])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @param  array<string, array<string, bool|int|string>>  $preferences
     */
    public function updatePreferences(User $user, Account $account, ?Brand $brand, array $preferences): void
    {
        $this->assertUserTenant($user, $account, $brand);

        foreach (NotificationPreference::TYPES as $type) {
            foreach (NotificationPreference::CHANNELS as $channel) {
                NotificationPreference::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'account_id' => $account->id,
                        'brand_id' => $brand?->id,
                        'type' => $type,
                        'channel' => $channel,
                    ],
                    [
                        'enabled' => (bool) ($preferences[$type][$channel] ?? false),
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, NotificationEvent>
     */
    public function notify(Account $account, ?Brand $brand, string $type, string $title, string $body, array $payload = [], ?DomainEvent $domainEvent = null): Collection
    {
        if (! in_array($type, NotificationPreference::TYPES, true)) {
            throw new InvalidArgumentException("Invalid notification type [{$type}].");
        }

        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Notification brand must belong to the account.');
        }

        return $this->recipients($account, $brand)
            ->flatMap(function (User $user) use ($account, $brand, $type, $title, $body, $payload, $domainEvent): array {
                if (! $this->enabled($user, $account, $brand, $type, 'in_app')) {
                    return [];
                }

                $attributes = [
                    'account_id' => $account->id,
                    'brand_id' => $brand?->id,
                    'title' => $title,
                    'body' => $body,
                    'payload' => $payload,
                    'delivered_at' => now(),
                ];

                $event = $domainEvent
                    ? NotificationEvent::query()->updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'domain_event_id' => $domainEvent->id,
                            'type' => $type,
                            'channel' => 'in_app',
                        ],
                        $attributes,
                    )
                    : NotificationEvent::query()->create([
                        'user_id' => $user->id,
                        'domain_event_id' => null,
                        'account_id' => $account->id,
                        'brand_id' => $brand?->id,
                        'type' => $type,
                        'channel' => 'in_app',
                        'title' => $title,
                        'body' => $body,
                        'payload' => $payload,
                        'delivered_at' => now(),
                    ]);

                return [$event->refresh()];
            })
            ->values();
    }

    public function notifyForDomainEvent(DomainEvent $event): Collection
    {
        $attributes = $this->attributesForDomainEvent($event);

        if ($attributes === null) {
            return collect();
        }

        return $this->notify(
            $event->account,
            $event->brand,
            $attributes['type'],
            $attributes['title'],
            $attributes['body'],
            [
                ...($attributes['payload'] ?? []),
                'domain_event_id' => $event->id,
                'domain_event_type' => $event->event_type,
            ],
            $event,
        );
    }

    private function attributesForDomainEvent(DomainEvent $event): ?array
    {
        $payload = $event->payload ?? [];

        return match ($event->event_type) {
            'IntegrationDisconnected' => [
                'type' => 'integration_expired',
                'title' => 'Integration needs attention',
                'body' => 'An integration disconnected or expired and may need to be reconnected.',
                'payload' => $payload,
            ],
            'ContentPublishingFailed', 'SocialPostFailed', 'VisibilityProviderRunFailed', 'VisibilityRunScheduleFailed' => [
                'type' => 'publishing_failed',
                'title' => 'Publishing or provider workflow failed',
                'body' => $payload['error_message'] ?? $payload['message'] ?? 'A publishing or provider workflow failed.',
                'payload' => $payload,
            ],
            'VisibilityCheckCompleted' => ((int) ($payload['score'] ?? 100)) < 40 ? [
                'type' => 'visibility_drop',
                'title' => 'Visibility dropped',
                'body' => 'A visibility check scored below the configured attention threshold.',
                'payload' => $payload,
            ] : null,
            'VisibilityProviderRunCompleted' => ((int) ($payload['visibility_score'] ?? 100)) < 40 ? [
                'type' => 'visibility_drop',
                'title' => 'AI visibility dropped',
                'body' => 'A provider run returned a low AI visibility score.',
                'payload' => $payload,
            ] : null,
            'RecommendationCreated' => [
                'type' => 'recommendation_created',
                'title' => 'New recommendation',
                'body' => $payload['title'] ?? 'A new recommendation is ready for review.',
                'payload' => $payload,
            ],
            'PerformanceInsightDetected' => $this->performanceInsightNotification($payload),
            'ApprovalRequested' => [
                'type' => 'approval_requested',
                'title' => 'Approval requested',
                'body' => 'A workflow item is waiting for approval.',
                'payload' => $payload,
            ],
            'AgentTaskCompleted' => [
                'type' => 'agent_task_completed',
                'title' => 'Agent task completed',
                'body' => $payload['title'] ?? 'An agent task has been completed.',
                'payload' => $payload,
            ],
            'CreditsLow' => [
                'type' => 'credits_low',
                'title' => 'Credits are low',
                'body' => 'The account credit balance is low.',
                'payload' => $payload,
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function performanceInsightNotification(array $payload): ?array
    {
        $insightType = $payload['performance_insight_type'] ?? null;

        return match ($insightType) {
            'traffic_drop' => [
                'type' => 'traffic_drop',
                'title' => 'Traffic drop detected',
                'body' => $payload['title'] ?? 'A content traffic drop was detected.',
                'payload' => $payload,
            ],
            'visibility_gap', 'ranking_drop' => [
                'type' => 'visibility_drop',
                'title' => 'Visibility drop detected',
                'body' => $payload['title'] ?? 'A visibility performance gap was detected.',
                'payload' => $payload,
            ],
            default => null,
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Account $account, ?Brand $brand): Collection
    {
        return User::query()
            ->whereHas('memberships', fn (Builder $query) => $query->where('account_id', $account->id)->where('status', 'active'))
            ->when($brand !== null, fn (Builder $query) => $query->whereHas('brandMemberships', fn (Builder $membership) => $membership->where('account_id', $account->id)->where('brand_id', $brand->id)->where('status', 'active')))
            ->get();
    }

    private function enabled(User $user, Account $account, ?Brand $brand, string $type, string $channel): bool
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('account_id', $account->id)
            ->where('brand_id', $brand?->id)
            ->where('type', $type)
            ->where('channel', $channel)
            ->first();

        return $preference?->enabled ?? ($channel === 'in_app');
    }

    private function assertUserTenant(User $user, Account $account, ?Brand $brand): void
    {
        $hasAccount = $user->memberships()->where('account_id', $account->id)->where('status', 'active')->exists();

        if (! $hasAccount) {
            throw new InvalidArgumentException('Notification preferences must belong to an accessible account.');
        }

        if ($brand !== null && ! $user->brandMemberships()->where('account_id', $account->id)->where('brand_id', $brand->id)->where('status', 'active')->exists()) {
            throw new InvalidArgumentException('Notification preferences must belong to an accessible brand.');
        }
    }
}
