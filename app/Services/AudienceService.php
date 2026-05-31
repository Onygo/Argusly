<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Audience;
use App\Models\AudienceMember;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Segment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AudienceService
{
    /**
     * @return LengthAwarePaginator<int, Audience>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, int $perPage = 15): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Audience::query()
            ->forTenant($account, $brand)
            ->with(['brand'])
            ->withCount(['members', 'segments'])
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): Audience
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Audience::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'members.contact', 'segments'])
            ->withCount(['members', 'segments'])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createAudience(Account $account, ?Brand $brand, array $attributes): Audience
    {
        $scopeBrand = $this->scopeBrand($account, $brand, $attributes['scope'] ?? 'brand');

        return Audience::query()->create([
            'account_id' => $account->id,
            'brand_id' => $scopeBrand?->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'metadata' => [
                'source' => 'manual_foundation',
                'sending_enabled' => false,
                'imports_enabled' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function addMember(Audience $audience, array $attributes): AudienceMember
    {
        $email = Str::lower($attributes['email']);
        $contact = $this->resolveContact($audience->account, $attributes['contact_id'] ?? null, $email);

        return AudienceMember::query()->updateOrCreate(
            [
                'audience_id' => $audience->id,
                'email' => $email,
            ],
            [
                'account_id' => $audience->account_id,
                'contact_id' => $contact?->id,
                'first_name' => $attributes['first_name'] ?? $contact?->first_name,
                'last_name' => $attributes['last_name'] ?? $contact?->last_name,
                'status' => $attributes['status'] ?? 'active',
                'source' => $attributes['source'] ?? 'manual',
                'metadata' => [
                    'contact_reused' => $contact !== null,
                    'sending_enabled' => false,
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createSegment(Account $account, ?Brand $brand, array $attributes): Segment
    {
        $scopeBrand = $this->scopeBrand($account, $brand, $attributes['scope'] ?? 'brand');
        $audience = $this->audience($account, $scopeBrand, $attributes['audience_id'] ?? null);

        return Segment::query()->create([
            'account_id' => $account->id,
            'brand_id' => $scopeBrand?->id,
            'audience_id' => $audience?->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'rules' => $attributes['rules'] ?? null,
            'status' => $attributes['status'] ?? 'active',
        ]);
    }

    /**
     * @return Collection<int, Contact>
     */
    public function contacts(Account $account): Collection
    {
        return Contact::query()
            ->where('account_id', $account->id)
            ->whereNotNull('email')
            ->orderBy('display_name')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, Audience>
     */
    public function audiences(Account $account, ?Brand $brand = null): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Audience::query()
            ->forTenant($account, $brand)
            ->orderBy('name')
            ->limit(100)
            ->get();
    }

    /**
     * @return Collection<int, Segment>
     */
    public function segments(Account $account, ?Brand $brand = null): Collection
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Segment::query()
            ->forTenant($account, $brand)
            ->with('audience')
            ->latest()
            ->limit(100)
            ->get();
    }

    private function ensureBrandBelongsToAccount(Account $account, ?Brand $brand): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Audience brand must belong to the account.');
        }
    }

    private function scopeBrand(Account $account, ?Brand $brand, mixed $scope): ?Brand
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return $scope === 'account' ? null : $brand;
    }

    private function resolveContact(Account $account, mixed $contactId, string $email): ?Contact
    {
        if ($contactId !== null && $contactId !== '') {
            return Contact::query()
                ->where('account_id', $account->id)
                ->findOrFail((int) $contactId);
        }

        return Contact::query()
            ->where('account_id', $account->id)
            ->where('email', $email)
            ->first();
    }

    private function audience(Account $account, ?Brand $brand, mixed $audienceId): ?Audience
    {
        if ($audienceId === null || $audienceId === '') {
            return null;
        }

        return Audience::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $brandScope) => $brandScope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->findOrFail((int) $audienceId);
    }
}
