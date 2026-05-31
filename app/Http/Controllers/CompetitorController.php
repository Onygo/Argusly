<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Competitor;
use App\Models\User;
use App\Services\CompetitorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompetitorController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        CompetitorService $competitors,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', Competitor::class);

        return view('app.competitors.index', [
            'account' => $account,
            'brand' => $brand,
            'comparison' => $competitors->compare($account, $brand),
            'statuses' => Competitor::STATUSES,
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        CompetitorService $competitors,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('create', Competitor::class);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'website' => ['required', 'string', 'max:2048'],
            'industry' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(Competitor::STATUSES)],
        ]);

        $competitors->add($account, $brand, $attributes);

        return redirect()->route('app.competitors')->with('status', 'Competitor added.');
    }
}
