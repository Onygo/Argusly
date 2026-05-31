<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Agent;
use App\Models\User;
use App\Services\AgentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AgentController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        AgentManager $agents,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Agent::class);

        return view('app.agents.index', [
            'account' => $account,
            'brand' => $brand,
            'agents' => $agents->agents(),
            'latestRuns' => $agents->latestRuns($account, $brand),
            'latestRecommendations' => $agents->latestRecommendations($account, $brand),
        ]);
    }
}
