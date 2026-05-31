<?php

namespace App\Contracts;

use App\Models\Brand;
use App\Models\User;

interface CurrentBrandContract
{
    public function get(?User $user = null): ?Brand;

    public function id(?User $user = null): ?int;

    public function set(Brand|int $brand, ?User $user = null): Brand;

    public function switch(Brand|int $brand, ?User $user = null): Brand;

    public function forget(?User $user = null): void;

    public function userCanAccess(User $user, Brand|int $brand): bool;
}
