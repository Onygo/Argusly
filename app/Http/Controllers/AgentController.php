<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Briefing;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\AgenticMarketingWorkflowService;
use App\Services\AgentManager;
use App\Services\BriefingService;
use App\Services\RecommendationService;
use Illuminate\Http\RedirectResponse;
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
        AgenticMarketingWorkflowService $workflows,
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
            'workflow' => $workflows->dashboard($account, $brand),
        ]);
    }

    public function tasks(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        AgenticMarketingWorkflowService $workflows,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Agent::class);

        return view('app.agents.tasks', [
            'account' => $account,
            'brand' => $brand,
            'tasks' => $workflows->paginatedTasks($account, $brand),
            'workflow' => $workflows->dashboard($account, $brand),
        ]);
    }

    public function runs(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        AgenticMarketingWorkflowService $workflows,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        Gate::authorize('viewAny', Agent::class);

        return view('app.agents.runs', [
            'account' => $account,
            'brand' => $brand,
            'runs' => $workflows->paginatedRuns($account, $brand),
            'workflow' => $workflows->dashboard($account, $brand),
        ]);
    }

    public function planRecommendation(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        RecommendationService $recommendations,
        AgenticMarketingWorkflowService $workflows,
        Recommendation $recommendation,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        $recommendation = $recommendations->findForTenant($account, $brand, $recommendation->id);

        $task = $workflows->planRecommendation($recommendation, $user);

        return redirect()
            ->route('app.agents.tasks')
            ->with('status', $task ? 'Agent workflow planned and sent for approval.' : 'Recommendation has no supported agent action.');
    }

    public function planBriefing(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        BriefingService $briefings,
        AgenticMarketingWorkflowService $workflows,
        Briefing $briefing,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account, 403);
        $briefing = $briefings->findForTenant($account, $brand, $briefing->id);

        $workflows->planBriefing($briefing, $user);

        return redirect()->route('app.agents.tasks')->with('status', 'Briefing workflow planned and sent for approval.');
    }

    public function requestTaskApproval(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        AgentTask $agentTask,
        AgenticMarketingWorkflowService $workflows,
    ): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->assertTaskInCurrentScope($user, $currentAccount, $currentBrand, $agentTask);
        $workflows->requestTaskApproval($agentTask, $user, $request->input('notes'));

        return back()->with('status', 'Agent task approval requested.');
    }

    public function queueTask(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        AgentTask $agentTask,
        AgenticMarketingWorkflowService $workflows,
    ): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->assertTaskInCurrentScope($user, $currentAccount, $currentBrand, $agentTask);
        $workflows->queueTask($agentTask, $user);

        return back()->with('status', 'Agent task queued.');
    }

    public function runTask(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        AgentTask $agentTask,
        AgenticMarketingWorkflowService $workflows,
    ): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->assertTaskInCurrentScope($user, $currentAccount, $currentBrand, $agentTask);
        $workflows->runTask($agentTask, $user);

        return redirect()->route('app.agents.runs')->with('status', 'Agent task completed in guarded runtime.');
    }

    private function assertTaskInCurrentScope(
        User $user,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        AgentTask $task,
    ): void {
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        abort_unless($account && $task->account_id === $account->id, 404);
        abort_unless($brand === null ? $task->brand_id === null : in_array($task->brand_id, [null, $brand->id], true), 404);
    }
}
