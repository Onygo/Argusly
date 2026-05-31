<?php

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Account;
use App\Models\Brand;

if (! function_exists('current_account')) {
    function current_account(): ?Account
    {
        return app(CurrentAccountContract::class)->get();
    }
}

if (! function_exists('current_account_id')) {
    function current_account_id(): ?int
    {
        return app(CurrentAccountContract::class)->id();
    }
}

if (! function_exists('current_brand')) {
    function current_brand(): ?Brand
    {
        return app(CurrentBrandContract::class)->get();
    }
}

if (! function_exists('current_brand_id')) {
    function current_brand_id(): ?int
    {
        return app(CurrentBrandContract::class)->id();
    }
}
