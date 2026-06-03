<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Competitor;
use App\Models\Entity;
use App\Models\Mention;
use App\Models\Narrative;
use App\Models\NarrativeGap;
use App\Models\NarrativeObservation;
use App\Models\Recommendation;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisibilityProviderRun;
use App\Services\NarrativeIntelligenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NarrativeController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        NarrativeIntelligenceService $narratives,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('viewAny', Narrative::class);

        $filters = $request->validate([
            'type' => ['nullable', 'string', Rule::in(Narrative::TYPES)],
            'status' => ['nullable', 'string', Rule::in(Narrative::STATUSES)],
            'importance' => ['nullable', 'string', Rule::in(Narrative::IMPORTANCE_LEVELS)],
        ]);

        return view('app.narratives.index', [
            'account' => $account,
            'brand' => $brand,
            'narratives' => $narratives->paginatedForTenant($account, $brand, $filters),
            'stats' => $narratives->dashboardStats($account, $brand),
            'openGaps' => $narratives->openGaps($account, $brand),
            'recommendations' => Recommendation::query()
                ->where('account_id', $account->id)
                ->where('brand_id', $brand->id)
                ->whereHas('signal', fn (Builder $query) => $query->where('type', 'narrative_gap_detected'))
                ->latest('created_at')
                ->limit(8)
                ->get(),
            'filters' => $filters,
            'types' => Narrative::TYPES,
            'statuses' => Narrative::STATUSES,
            'importanceLevels' => Narrative::IMPORTANCE_LEVELS,
            'sentiments' => NarrativeObservation::SENTIMENTS,
            'topics' => Topic::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->orderBy('name')->limit(50)->get(),
            'entities' => Entity::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->orderBy('name')->limit(50)->get(),
            'mentions' => Mention::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->latest()->limit(50)->get(),
            'competitors' => Competitor::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->orderBy('name')->limit(50)->get(),
            'visibilityRuns' => VisibilityProviderRun::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->latest()->limit(50)->get(),
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        NarrativeIntelligenceService $narratives,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('create', Narrative::class);

        $narratives->createNarrative($account, $brand, $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'narrative_type' => ['required', 'string', Rule::in(Narrative::TYPES)],
            'status' => ['required', 'string', Rule::in(Narrative::STATUSES)],
            'importance' => ['required', 'string', Rule::in(Narrative::IMPORTANCE_LEVELS)],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer'],
            'entity_ids' => ['nullable', 'array'],
            'entity_ids.*' => ['integer'],
            'mention_ids' => ['nullable', 'array'],
            'mention_ids.*' => ['integer'],
            'competitor_ids' => ['nullable', 'array'],
            'competitor_ids.*' => ['integer'],
            'visibility_provider_run_ids' => ['nullable', 'array'],
            'visibility_provider_run_ids.*' => ['integer'],
        ]));

        return redirect()->route('app.narratives.index')->with('status', 'Narrative created.');
    }

    public function storeObservation(
        Request $request,
        Narrative $narrative,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        NarrativeIntelligenceService $narratives,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('update', $narrative);

        $narratives->recordObservation($account, $brand, $narrative, $request->validate([
            'source_type' => ['nullable', 'string', 'max:255'],
            'source_id' => ['nullable', 'integer'],
            'observation' => ['required', 'string'],
            'sentiment' => ['nullable', 'string', Rule::in(NarrativeObservation::SENTIMENTS)],
            'confidence_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'detected_at' => ['nullable', 'date'],
        ]));

        return redirect()->route('app.narratives.index')->with('status', 'Narrative observation recorded.');
    }

    public function storeGap(
        Request $request,
        Narrative $narrative,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        NarrativeIntelligenceService $narratives,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $brand, 403);
        Gate::authorize('update', $narrative);

        $narratives->detectGap($account, $brand, $narrative, $request->validate([
            'desired_state' => ['required', 'string'],
            'detected_state' => ['required', 'string'],
            'gap_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(NarrativeGap::STATUSES)],
        ]));

        return redirect()->route('app.narratives.index')->with('status', 'Narrative gap detected and recommendations created.');
    }
}
