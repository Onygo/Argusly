<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\MarketingTask;
use App\Models\User;
use App\Services\MarketingTaskService;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MarketingTaskController extends Controller
{
    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        MarketingTaskService $tasks,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', MarketingTask::class);

        $tasks->create($account, $brand, $user, $request->validate([
            'scope' => ['required', 'string', Rule::in(['brand', 'account'])],
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'marketing_objective_id' => ['nullable', 'integer', 'exists:marketing_objectives,id'],
            'related_type' => ['nullable', 'string', Rule::in(array_keys($tasks->relatedTypes()))],
            'related_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(MarketingTask::STATUSES)],
            'priority' => ['required', 'string', Rule::in(MarketingTask::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]));

        return redirect()->route('app.marketing')->with('status', 'Marketing task created.');
    }

    public function storeFromRecommendation(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        RecommendationService $recommendations,
        MarketingTaskService $tasks,
        int $recommendation,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('create', MarketingTask::class);

        $recommendationModel = $recommendations->findForTenant($account, $brand, $recommendation);

        $tasks->createFromRecommendation($account, $brand, $recommendationModel, $user, $request->validate([
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'marketing_objective_id' => ['nullable', 'integer', 'exists:marketing_objectives,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', Rule::in(MarketingTask::STATUSES)],
            'priority' => ['nullable', 'string', Rule::in(MarketingTask::PRIORITIES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
        ]));

        return back()->with('status', 'Recommendation task created.');
    }
}
