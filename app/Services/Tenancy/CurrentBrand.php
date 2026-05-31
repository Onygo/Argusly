<?php

namespace App\Services\Tenancy;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Brand;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CurrentBrand implements CurrentBrandContract
{
    private ?Brand $resolved = null;

    private ?int $resolvedForUserId = null;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly CacheRepository $cache,
        private readonly SessionManager $session,
        private readonly CurrentAccountContract $currentAccount,
    ) {}

    public function get(?User $user = null): ?Brand
    {
        $user ??= $this->auth->guard()->user();

        if (! $user instanceof User || ! Schema::hasTable('brands')) {
            return null;
        }

        if ($this->resolvedForUserId === $user->id && $this->resolved && $this->userCanAccess($user, $this->resolved) && $this->belongsToCurrentAccount($this->resolved, $user)) {
            return $this->resolved;
        }

        $brandId = $this->storedId($user);

        if ($brandId !== null && ! $this->userCanAccess($user, $brandId)) {
            $this->forget($user);
            $brandId = null;
        }

        $brandId ??= $this->firstAccessibleBrandId($user);

        if ($brandId === null) {
            $this->forget($user);

            return null;
        }

        return $this->set($brandId, $user);
    }

    public function id(?User $user = null): ?int
    {
        return $this->get($user)?->id;
    }

    public function set(Brand|int $brand, ?User $user = null): Brand
    {
        $user ??= $this->auth->guard()->user();

        if (! $user instanceof User) {
            throw new AccessDeniedHttpException('A user is required to resolve a brand context.');
        }

        $brand = $brand instanceof Brand ? $brand : Brand::query()->findOrFail($brand);

        if (! $this->userCanAccess($user, $brand)) {
            $this->forget($user);

            throw new AccessDeniedHttpException('You do not have access to this brand.');
        }

        $accountId = $this->currentAccount->id($user);

        if ($accountId !== null && $brand->account_id !== $accountId) {
            $this->forget($user);

            throw new AccessDeniedHttpException('The selected brand does not belong to the current account.');
        }

        if ($accountId === null) {
            $this->currentAccount->set($brand->account_id, $user);
        }

        $this->resolved = $brand;
        $this->resolvedForUserId = $user->id;
        $this->session->put($this->sessionKey(), $brand->id);
        $this->cache->put($this->cacheKey($user), $brand->id, now()->addDays(30));

        return $brand;
    }

    public function switch(Brand|int $brand, ?User $user = null): Brand
    {
        $brand = $this->set($brand, $user);

        app(ActivityLogger::class)->log(
            event: 'context.switched',
            description: "Context switched to brand {$brand->name}.",
            account: $brand->account,
            brand: $brand,
            user: $user instanceof User ? $user : null,
            subject: $brand,
            properties: ['context' => 'brand'],
        );

        return $brand;
    }

    public function forget(?User $user = null): void
    {
        $user ??= $this->auth->guard()->user();

        $this->resolved = null;
        $this->resolvedForUserId = null;
        $this->session->forget($this->sessionKey());

        if ($user instanceof User) {
            $this->cache->forget($this->cacheKey($user));
        }
    }

    public function userCanAccess(User $user, Brand|int $brand): bool
    {
        $brand = $brand instanceof Brand ? $brand : Brand::query()->find($brand);

        if (! $brand) {
            return false;
        }

        return $this->currentAccount->userCanAccess($user, $brand->account_id)
            && $user->brandMemberships()
                ->where('brand_id', $brand->id)
                ->where('account_id', $brand->account_id)
                ->where('status', 'active')
                ->whereHas('brand', fn ($query) => $query->where('status', 'active'))
                ->exists();
    }

    private function belongsToCurrentAccount(Brand $brand, User $user): bool
    {
        $accountId = $this->currentAccount->id($user);

        return $accountId === null || $brand->account_id === $accountId;
    }

    private function storedId(User $user): ?int
    {
        $sessionId = $this->session->get($this->sessionKey());

        if ($sessionId !== null) {
            return (int) $sessionId;
        }

        $cacheId = $this->cache->get($this->cacheKey($user));

        return $cacheId === null ? null : (int) $cacheId;
    }

    private function firstAccessibleBrandId(User $user): ?int
    {
        $accountId = $this->currentAccount->id($user);

        return $user->brandMemberships()
            ->where('status', 'active')
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->orderBy('id')
            ->value('brand_id');
    }

    private function sessionKey(): string
    {
        return 'tenant.current_brand_id';
    }

    private function cacheKey(User $user): string
    {
        return "tenant-context:user:{$user->id}:brand";
    }
}
