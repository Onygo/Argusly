<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\User;
use App\Services\DomainEventService;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function accept(
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
        $recommendationModel->accept();

        app(DomainEventService::class)->recordForSubject('RecommendationAccepted', $recommendationModel->refresh(), $user, [
            'title' => $recommendationModel->title,
            'signal_id' => $recommendationModel->signal_id,
            'impact_score' => $recommendationModel->impact_score,
            'confidence_score' => $recommendationModel->confidence_score,
        ]);

        return back()->with('status', 'Recommendation accepted.');
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
