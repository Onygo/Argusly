<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Relationship;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class RelationshipIntelligenceService
{
    /**
     * @return LengthAwarePaginator<int, Contact>
     */
    public function contactsForAccount(Account $account, int $perPage = 12): LengthAwarePaginator
    {
        return Contact::query()
            ->where('account_id', $account->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return LengthAwarePaginator<int, Organization>
     */
    public function organizationsForAccount(Account $account, int $perPage = 12): LengthAwarePaginator
    {
        return Organization::query()
            ->where('account_id', $account->id)
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return Collection<int, Relationship>
     */
    public function relationshipsForAccount(Account $account): Collection
    {
        return Relationship::query()
            ->where('account_id', $account->id)
            ->with(['from', 'to'])
            ->latest()
            ->limit(100)
            ->get();
    }

    /**
     * @return array{contacts: int, organizations: int, relationships: int, typeCounts: Collection<string, int>, futureLanes: array<int, array{label: string, status: string}>}
     */
    public function graph(Account $account): array
    {
        $relationships = $this->relationshipsForAccount($account);

        return [
            'contacts' => Contact::query()->where('account_id', $account->id)->count(),
            'organizations' => Organization::query()->where('account_id', $account->id)->count(),
            'relationships' => $relationships->count(),
            'typeCounts' => $relationships->groupBy('relationship_type')->map->count(),
            'futureLanes' => [
                ['label' => 'Influencers', 'status' => 'ready'],
                ['label' => 'Journalists', 'status' => 'ready'],
                ['label' => 'Media', 'status' => 'ready'],
                ['label' => 'Stakeholders', 'status' => 'ready'],
                ['label' => 'Experts', 'status' => 'ready'],
            ],
        ];
    }

    /**
     * @param  array{first_name: string, last_name: string, display_name?: string|null, email?: string|null, phone?: string|null, website?: string|null, linkedin_url?: string|null, notes?: string|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function createContact(Account $account, array $attributes): Contact
    {
        return Contact::query()->create([
            'account_id' => $account->id,
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'display_name' => $attributes['display_name'] ?? null,
            'email' => $attributes['email'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'website' => $attributes['website'] ?? null,
            'linkedin_url' => $attributes['linkedin_url'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'metadata' => $attributes['metadata'] ?? ['capture_mode' => 'manual_foundation'],
        ]);
    }

    /**
     * @param  array{name: string, website?: string|null, industry?: string|null, description?: string|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function createOrganization(Account $account, array $attributes): Organization
    {
        return Organization::query()->create([
            'account_id' => $account->id,
            'name' => $attributes['name'],
            'website' => $attributes['website'] ?? null,
            'industry' => $attributes['industry'] ?? null,
            'description' => $attributes['description'] ?? null,
            'metadata' => $attributes['metadata'] ?? ['capture_mode' => 'manual_foundation'],
        ]);
    }

    /**
     * @param  array{from_type: string, from_id: int|string, to_type: string, to_id: int|string, relationship_type: string, strength?: int|string|null, metadata?: array<string, mixed>|null}  $attributes
     */
    public function createRelationship(Account $account, array $attributes): Relationship
    {
        if (! in_array($attributes['relationship_type'], Relationship::TYPES, true)) {
            throw new InvalidArgumentException("Invalid relationship type [{$attributes['relationship_type']}].");
        }

        $from = $this->resolveNode($account, $attributes['from_type'], (int) $attributes['from_id']);
        $to = $this->resolveNode($account, $attributes['to_type'], (int) $attributes['to_id']);

        if ($from::class === $to::class && $from->id === $to->id) {
            throw new InvalidArgumentException('Relationship endpoints must be different.');
        }

        return Relationship::query()->firstOrCreate(
            [
                'account_id' => $account->id,
                'from_type' => $from::class,
                'from_id' => $from->id,
                'to_type' => $to::class,
                'to_id' => $to->id,
                'relationship_type' => $attributes['relationship_type'],
            ],
            [
                'strength' => isset($attributes['strength']) && $attributes['strength'] !== null
                    ? max(0, min(100, (int) $attributes['strength']))
                    : null,
                'metadata' => $attributes['metadata'] ?? ['capture_mode' => 'manual_foundation'],
            ],
        );
    }

    public function findContact(Account $account, int $id): Contact
    {
        return Contact::query()
            ->where('account_id', $account->id)
            ->with(['outgoingRelationships.to', 'incomingRelationships.from'])
            ->findOrFail($id);
    }

    public function findOrganization(Account $account, int $id): Organization
    {
        return Organization::query()
            ->where('account_id', $account->id)
            ->with(['outgoingRelationships.to', 'incomingRelationships.from'])
            ->findOrFail($id);
    }

    /**
     * @return Collection<int, Contact|Organization>
     */
    public function nodes(Account $account): Collection
    {
        return Contact::query()
            ->where('account_id', $account->id)
            ->orderBy('display_name')
            ->get()
            ->map(fn (Contact $contact) => $contact)
            ->concat(
                Organization::query()
                    ->where('account_id', $account->id)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Organization $organization) => $organization),
            )
            ->values();
    }

    private function resolveNode(Account $account, string $type, int $id): Model
    {
        $class = match ($type) {
            Contact::class, 'contact' => Contact::class,
            Organization::class, 'organization' => Organization::class,
            default => throw new InvalidArgumentException('Unsupported relationship endpoint type.'),
        };

        /** @var Contact|Organization $node */
        $node = $class::query()
            ->where('account_id', $account->id)
            ->findOrFail($id);

        return $node;
    }

    public function nodeLabel(Model $node): string
    {
        return $node instanceof Contact
            ? ($node->display_name ?: trim("{$node->first_name} {$node->last_name}"))
            : $node->name;
    }

    public function nodeType(Model $node): string
    {
        return $node instanceof Contact ? 'contact' : 'organization';
    }
}
