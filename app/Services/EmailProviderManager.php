<?php

namespace App\Services;

use App\Contracts\EmailProviderInterface;
use App\Models\Account;
use App\Models\Brand;
use App\Models\EmailProvider;
use App\Services\Email\FakeEmailProvider;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class EmailProviderManager
{
    public function __construct(private readonly FakeEmailProvider $fake) {}

    /**
     * @return LengthAwarePaginator<int, EmailProvider>
     */
    public function paginatedForTenant(Account $account, ?Brand $brand = null, int $perPage = 15): LengthAwarePaginator
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return EmailProvider::query()
            ->forTenant($account, $brand)
            ->with('brand')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Account $account, ?Brand $brand, array $attributes): EmailProvider
    {
        $scopeBrand = $this->scopeBrand($account, $brand, $attributes['scope'] ?? 'brand');

        return EmailProvider::query()->create([
            'account_id' => $account->id,
            'brand_id' => $scopeBrand?->id,
            'provider' => $attributes['provider'],
            'name' => $attributes['name'],
            'status' => $attributes['status'] ?? 'active',
            'settings' => $attributes['settings'] ?? ['mode' => 'placeholder'],
            'credentials' => $attributes['credentials'] ?? [],
        ]);
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): EmailProvider
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return EmailProvider::query()
            ->forTenant($account, $brand)
            ->findOrFail($id);
    }

    public function providerFor(EmailProvider $provider): EmailProviderInterface
    {
        return $this->fake;
    }

    /**
     * @return array{ok: bool, provider: string, message_id: string, to: string, subject: string}
     */
    public function sendTestEmail(EmailProvider $provider, string $to): array
    {
        $result = $this->providerFor($provider)->sendTestEmail($provider, $to);

        if ($result['ok']) {
            $provider->forceFill([
                'status' => 'active',
                'last_verified_at' => now(),
            ])->save();
        }

        return $result;
    }

    /**
     * @param  array{subject: string, html?: string|null, text?: string|null, metadata?: array<string, mixed>|null}  $payload
     * @return array{ok: bool, provider: string, message_id?: string|null, to: string, subject: string, error?: string|null}
     */
    public function sendNewsletterEmail(EmailProvider $provider, string $to, array $payload): array
    {
        return $this->providerFor($provider)->sendNewsletterEmail($provider, $to, $payload);
    }

    private function ensureBrandBelongsToAccount(Account $account, ?Brand $brand): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Email provider brand must belong to the account.');
        }
    }

    private function scopeBrand(Account $account, ?Brand $brand, mixed $scope): ?Brand
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return $scope === 'account' ? null : $brand;
    }
}
