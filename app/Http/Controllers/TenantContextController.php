<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantContextController extends Controller
{
    public function switchAccount(Request $request, CurrentAccountContract $currentAccount): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
        ]);

        $currentAccount->switch((int) $validated['account_id'], $request->user());

        return back();
    }

    public function switchBrand(Request $request, CurrentBrandContract $currentBrand): RedirectResponse
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'integer'],
        ]);

        $currentBrand->switch((int) $validated['brand_id'], $request->user());

        return back();
    }
}
