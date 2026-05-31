<?php

namespace App\Contracts;

use App\Models\Account;
use App\Models\User;

interface CurrentAccountContract
{
    public function get(?User $user = null): ?Account;

    public function id(?User $user = null): ?int;

    public function set(Account|int $account, ?User $user = null): Account;

    public function switch(Account|int $account, ?User $user = null): Account;

    public function forget(?User $user = null): void;

    public function userCanAccess(User $user, Account|int $account): bool;
}
