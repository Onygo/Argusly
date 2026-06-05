<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\IntelligenceSignal;
use App\Models\Mention;
use App\Models\User;
use App\Services\IntelligenceSignalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        Gate::authorize('viewAny', IntelligenceSignal::class);

        $brandIds = $signals->brandsForAccount($account)->pluck('id')->map(fn (int $id) => (string) $id)->all();

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'brand_id' => ['nullable', 'string', Rule::in(['account', ...$brandIds])],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'entity_id' => ['nullable', 'integer', 'exists:entities,id'],
            'sentiment' => ['nullable', 'string', Rule::in(Mention::SENTIMENTS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
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
            'brands' => $signals->brandsForAccount($account),
            'sources' => $signals->sourcesForTenant($account, $brand),
            'topics' => $signals->topicsForTenant($account, $brand),
            'entities' => $signals->entitiesForTenant($account, $brand),
            'sentiments' => Mention::SENTIMENTS,
        ]);
    }

    public function show(
        IntelligenceSignal $signal,
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
        Gate::authorize('view', $signal);

        return view('app.intelligence.show', [
            'signal' => $signals->findForTenant($account, $brand, $signal->id),
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

        $signalModel = $signals->findForTenant($account, $brand, $signal);
        Gate::authorize('update', $signalModel);
        $signalModel->markReviewed();

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

        $signalModel = $signals->findForTenant($account, $brand, $signal);
        Gate::authorize('update', $signalModel);
        $signalModel->dismiss();

        return back()->with('status', 'Signal dismissed.');
    }
}
