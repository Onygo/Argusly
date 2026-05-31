<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\User;
use App\Services\RecommendationActionService;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class RecommendationController extends Controller
{
    public function accept(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        RecommendationService $recommendations,
        RecommendationActionService $actions,
        int $recommendation,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);

        $recommendationModel = $recommendations->findForTenant($account, $brand, $recommendation);
        $actions->accept($recommendationModel, $user);

        return back()->with('status', 'Recommendation accepted.');
    }

    public function execute(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        RecommendationService $recommendations,
        RecommendationActionService $actions,
        int $recommendation,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);

        $recommendationModel = $recommendations->findForTenant($account, $brand, $recommendation);
        try {
            $actions->execute($recommendationModel, $user);
        } catch (Throwable $exception) {
            return back()->withErrors(['recommendation_action' => $exception->getMessage()]);
        }

        return back()->with('status', 'Recommendation action started.');
    }

    public function dismiss(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        RecommendationService $recommendations,
        int $recommendation,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);

        $recommendations->findForTenant($account, $brand, $recommendation)->dismiss();

        return back()->with('status', 'Recommendation dismissed.');
    }
}
