<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Briefing;
use App\Models\ContentAsset;
use App\Models\ContentLifecycleScore;
use App\Models\GeneratedAsset;
use App\Models\User;
use App\Services\BriefingService;
use App\Services\ContentOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;

class ContentOperationsController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ContentOperationsService $operations,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::forUser($user)->authorize('view_content', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        return view('app.content.operations', [
            'account' => $account,
            'brand' => $brand,
            'operations' => $operations->dashboard($account, $brand),
        ]);
    }

    public function planBriefing(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BriefingService $briefings,
        ContentOperationsService $operations,
        Briefing $briefing,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::forUser($user)->authorize('create_content', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $briefing = $briefings->findForTenant($account, $brand, $briefing->id);
        $operations->createContentPlanFromBriefing($briefing, $user);

        return back()->with('status', 'Content plan created from briefing.');
    }

    public function draftBriefing(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BriefingService $briefings,
        ContentOperationsService $operations,
        Briefing $briefing,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::forUser($user)->authorize('create_content', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $briefing = $briefings->findForTenant($account, $brand, $briefing->id);
        $asset = $operations->createDraftFromBriefing($briefing, $user);

        return redirect()->route('app.content.show', $asset)->with('status', 'Draft created from briefing.');
    }

    public function generateDraft(Request $request, ContentAsset $contentAsset, ContentOperationsService $operations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $contentAsset);

        $operations->requestDraftGeneration($contentAsset, $user, $request->input('type', 'refresh'));

        return back()->with('status', 'Draft generation queued.');
    }

    public function applyGeneratedDraft(
        Request $request,
        ContentAsset $contentAsset,
        GeneratedAsset $generatedAsset,
        ContentOperationsService $operations,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $contentAsset);

        try {
            $operations->applyGeneratedDraft($contentAsset, $generatedAsset, $user);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['generated_asset' => $exception->getMessage()]);
        }

        return back()->with('status', 'Generated draft applied.');
    }

    public function prepareDistribution(Request $request, ContentAsset $contentAsset, ContentOperationsService $operations): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('update', $contentAsset);

        $operations->prepareDistributionBundle($contentAsset, $user);

        return back()->with('status', 'Distribution bundle prepared.');
    }

    public function refreshRecommendation(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ContentOperationsService $operations,
        ContentLifecycleScore $score,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        abort_unless($score->account_id === $account->id && $score->brand_id === $brand->id, 404);
        Gate::forUser($user)->authorize('edit_content', ['account_id' => $account->id, 'brand_id' => $brand->id]);

        $operations->createRefreshRecommendation($score, $user);

        return back()->with('status', 'Refresh recommendation created.');
    }
}
