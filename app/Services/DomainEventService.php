<?php

namespace App\Services;

use App\Contracts\DomainEventProjector;
use App\Jobs\ProjectDomainEventJob;
use App\Models\Account;
use App\Models\Brand;
use App\Models\DomainEvent;
use App\Models\SourceSync;
use App\Models\User;
use App\Services\DomainEvents\ProjectorRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class DomainEventService
{
    /**
     * @param  iterable<int, DomainEventProjector>  $projectors
     */
    public function __construct(private readonly iterable $projectors = []) {}

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        string $eventType,
        Account $account,
        ?Brand $brand = null,
        ?Model $subject = null,
        ?User $actor = null,
        ?array $payload = null,
        mixed $occurredAt = null,
        bool $dispatch = true,
    ): DomainEvent {
        $this->validateEventType($eventType);
        $this->validateScope($account, $brand, $subject);

        $event = DomainEvent::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'event_type' => $eventType,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'actor_user_id' => $actor?->id,
            'payload' => $payload,
            'occurred_at' => $occurredAt ?? now(),
        ]);

        if ($dispatch) {
            ProjectDomainEventJob::dispatch($event->id);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function recordForSubject(string $eventType, Model $subject, ?User $actor = null, ?array $payload = null, mixed $occurredAt = null, bool $dispatch = true): DomainEvent
    {
        [$account, $brand] = $this->tenantForSubject($subject);

        return $this->record($eventType, $account, $brand, $subject, $actor, $payload, $occurredAt, $dispatch);
    }

    public function process(DomainEvent $event): DomainEvent
    {
        if ($event->processed_at !== null) {
            return $event;
        }

        app(ProjectorRegistry::class)->project($event);

        $event->forceFill(['processed_at' => now()])->save();

        return $event->refresh();
    }

    /**
     * @return Collection<int, DomainEventProjector>
     */
    public function projectors(): Collection
    {
        return collect($this->projectors);
    }

    public function canProject(): bool
    {
        return Schema::hasTable('domain_event_projector_runs');
    }

    /**
     * @param  array{event_type?: string|null}  $filters
     * @return LengthAwarePaginator<int, DomainEvent>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->tenantQuery($account, $brand)
            ->when($filters['event_type'] ?? null, fn (Builder $query, string $eventType) => $query->where('event_type', $eventType))
            ->with(['brand', 'actor'])
            ->latest('occurred_at')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Builder<DomainEvent>
     */
    public function tenantQuery(Account $account, ?Brand $brand = null): Builder
    {
        return DomainEvent::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            );
    }

    private function validateEventType(string $eventType): void
    {
        if (! in_array($eventType, DomainEvent::TYPES, true)) {
            throw new InvalidArgumentException("Invalid domain event type [{$eventType}].");
        }
    }

    private function validateScope(Account $account, ?Brand $brand, ?Model $subject): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Domain event brand must belong to the same account.');
        }

        if ($subject === null) {
            return;
        }

        [$subjectAccount, $subjectBrand] = $this->tenantForSubject($subject);

        if ($subjectAccount->id !== $account->id) {
            throw new InvalidArgumentException('Domain event subject must belong to the same account.');
        }

        if ($subjectBrand?->id !== $brand?->id) {
            throw new InvalidArgumentException('Domain event subject must belong to the same brand scope.');
        }
    }

    /**
     * @return array{Account, Brand|null}
     */
    private function tenantForSubject(Model $subject): array
    {
        if ($subject instanceof SourceSync) {
            $subject->loadMissing('source.account', 'source.brand');

            if (! $subject->source?->account) {
                throw new InvalidArgumentException('Source sync domain events require an account-scoped source.');
            }

            return [$subject->source->account, $subject->source->brand];
        }

        $account = $subject->getRelationValue('account') ?: ($subject->getAttribute('account_id') ? Account::query()->find($subject->getAttribute('account_id')) : null);
        $brand = $subject->getRelationValue('brand') ?: ($subject->getAttribute('brand_id') ? Brand::query()->find($subject->getAttribute('brand_id')) : null);

        if (! $account) {
            throw new InvalidArgumentException('Domain event subjects must be tenant scoped.');
        }

        return [$account, $brand];
    }
}
