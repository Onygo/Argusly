<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\IntelligenceSignal;
use App\Models\User;
use App\Services\OpportunityDiscoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OpportunityController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        OpportunityDiscoveryService $opportunities,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', IntelligenceSignal::class);

        return view('app.intelligence.opportunities', [
            'account' => $account,
            'brand' => $brand,
            'dashboard' => $opportunities->dashboard($account, $brand),
        ]);
    }

    public function project(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        OpportunityDiscoveryService $opportunities,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', IntelligenceSignal::class);

        $signals = $opportunities->project($account, $brand);

        return redirect()->route('app.intelligence.opportunities')->with('status', "Projected {$signals->count()} opportunity signal(s).");
    }
}
