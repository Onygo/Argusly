<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\GraphEdge;
use App\Models\GraphNode;
use App\Services\Graph\GraphOpportunityService;
use App\Services\Graph\GraphQueryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GraphController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        GraphQueryService $graph,
        GraphOpportunityService $opportunities,
    ): View {
        $user = $request->user();
        $account = $currentAccount->get($user);
        $brand = $currentBrand->get($user);

        $discovered = $account ? $opportunities->discover($account, $brand) : collect();

        return view('app.intelligence.graph', [
            'account' => $account,
            'brand' => $brand,
            'summary' => $account ? $graph->summary($account, $brand) : [],
            'opportunities' => $discovered,
            'nodes' => $account ? GraphNode::query()->forTenant($account, $brand)->latest()->limit(25)->get() : collect(),
            'edges' => $account ? GraphEdge::query()->forTenant($account, $brand)->with(['sourceNode', 'targetNode'])->latest()->limit(25)->get() : collect(),
        ]);
    }
}
