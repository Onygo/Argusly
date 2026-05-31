<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\IntelligenceSignal;
use App\Models\User;
use App\Services\IntelligenceSignalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IntelligenceSignalController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        IntelligenceSignalService $signals,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
        ]);

        return view('app.intelligence.index', [
            'account' => $account,
            'brand' => $brand,
            'signals' => $signals->paginatedForTenant($account, $brand, $filters),
            'filters' => $filters,
            'statuses' => IntelligenceSignal::STATUSES,
            'types' => IntelligenceSignal::TYPES,
            'categories' => IntelligenceSignal::CATEGORIES,
            'priorities' => IntelligenceSignal::PRIORITIES,
        ]);
    }

    public function markReviewed(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        IntelligenceSignalService $signals,
        int $signal,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);

        $signals->findForTenant($account, $brand, $signal)->markReviewed();

        return back()->with('status', 'Signal marked reviewed.');
    }

    public function dismiss(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        IntelligenceSignalService $signals,
        int $signal,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);

        $signals->findForTenant($account, $brand, $signal)->dismiss();

        return back()->with('status', 'Signal dismissed.');
    }
}
