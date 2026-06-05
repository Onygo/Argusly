<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\RecommendationActionService;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class RecommendationController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        RecommendationService $recommendations,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Recommendation::class);

        $brandIds = $recommendations->tenantBrands($account)->pluck('id')->map(fn (int $id) => (string) $id)->all();
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(Recommendation::STATUSES)],
            'brand_id' => ['nullable', 'string', Rule::in(['account', ...$brandIds])],
        ]);

        return view('app.intelligence.recommendations', [
            'account' => $account,
            'brand' => $brand,
            'filters' => $filters,
            'statuses' => Recommendation::STATUSES,
            'brands' => $recommendations->tenantBrands($account),
            'recommendations' => $recommendations->paginatedForTenant($account, $brand, $filters),
            'stats' => $recommendations->statisticsForTenant($account, $brand),
        ]);
    }

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
        Gate::authorize('update', $recommendationModel);
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
        Gate::authorize('update', $recommendationModel);
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

        $recommendationModel = $recommendations->findForTenant($account, $brand, $recommendation);
        Gate::authorize('update', $recommendationModel);
        $recommendationModel->dismiss();

        return back()->with('status', 'Recommendation dismissed.');
    }

    public function review(
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

        $recommendationModel = $recommendations->findForTenant($account, $brand, $recommendation);
        Gate::authorize('update', $recommendationModel);
        $recommendationModel->markReviewed();

        return back()->with('status', 'Recommendation reviewed.');
    }

    public function archive(
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

        $recommendationModel = $recommendations->findForTenant($account, $brand, $recommendation);
        Gate::authorize('update', $recommendationModel);
        $recommendationModel->archive();

        return back()->with('status', 'Recommendation archived.');
    }
}
