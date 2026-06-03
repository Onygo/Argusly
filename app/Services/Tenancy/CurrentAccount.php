<?php

namespace App\Services\Tenancy;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CurrentAccount implements CurrentAccountContract
{
    private ?bool $accountsTableExists = null;

    private ?Account $resolved = null;

    private ?int $resolvedForUserId = null;

    /**
     * @var array<string, bool>
     */
    private array $accessCache = [];

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly CacheRepository $cache,
        private readonly SessionManager $session,
    ) {}

    public function get(?User $user = null): ?Account
    {
        $user ??= $this->auth->guard()->user();

        if (! $user instanceof User) {
            return null;
        }

        if ($this->resolvedForUserId === $user->id && $this->resolved) {
            return $this->resolved;
        }

        if (! $this->accountsTableExists()) {
            return null;
        }

        $accountId = $this->storedId($user);

        if ($accountId !== null && ! $this->userCanAccess($user, $accountId)) {
            $this->forget($user);
            $accountId = null;
        }

        $accountId ??= $this->firstAccessibleAccountId($user);

        if ($accountId === null) {
            $this->forget($user);

            return null;
        }

        return $this->set($accountId, $user);
    }

    public function id(?User $user = null): ?int
    {
        return $this->get($user)?->id;
    }

    public function set(Account|int $account, ?User $user = null): Account
    {
        $user ??= $this->auth->guard()->user();

        if (! $user instanceof User) {
            throw new AccessDeniedHttpException('A user is required to resolve an account context.');
        }

        $account = $account instanceof Account ? $account : Account::query()->findOrFail($account);

        if (! $this->userCanAccess($user, $account)) {
            $this->forget($user);

            throw new AccessDeniedHttpException('You do not have access to this account.');
        }

        $this->resolved = $account;
        $this->resolvedForUserId = $user->id;
        $this->session->put($this->sessionKey(), $account->id);
        $this->cache->put($this->cacheKey($user), $account->id, now()->addDays(30));

        return $account;
    }

    public function switch(Account|int $account, ?User $user = null): Account
    {
        $account = $this->set($account, $user);

        app(CurrentBrandContract::class)->forget($user);

        app(ActivityLogger::class)->log(
            event: 'context.switched',
            description: "Context switched to account {$account->name}.",
            account: $account,
            user: $user instanceof User ? $user : null,
            subject: $account,
            properties: ['context' => 'account'],
        );

        return $account;
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

    public function userCanAccess(User $user, Account|int $account): bool
    {
        $accountId = $account instanceof Account ? $account->id : $account;
        $cacheKey = "{$user->id}:{$accountId}";

        if (array_key_exists($cacheKey, $this->accessCache)) {
            return $this->accessCache[$cacheKey];
        }

        return $this->accessCache[$cacheKey] = $user->memberships()
            ->where('account_id', $accountId)
            ->where('status', 'active')
            ->whereHas('account', fn ($query) => $query->where('status', 'active'))
            ->exists();
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

    private function firstAccessibleAccountId(User $user): ?int
    {
        return $user->memberships()
            ->where('status', 'active')
            ->orderBy('id')
            ->value('account_id');
    }

    private function sessionKey(): string
    {
        return 'tenant.current_account_id';
    }

    private function accountsTableExists(): bool
    {
        return $this->accountsTableExists ??= Schema::hasTable('accounts');
    }

    private function cacheKey(User $user): string
    {
        return "tenant-context:user:{$user->id}:account";
    }
}
