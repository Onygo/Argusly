<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Brand;
use App\Models\LlmRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiRuntimeMonitorController extends Controller
{
    public function index(Request $request): View
    {
        $base = LlmRequest::query()
            ->when($request->integer('account_id'), fn (Builder $query, int $accountId) => $query->where('account_id', $accountId))
            ->when($request->integer('brand_id'), fn (Builder $query, int $brandId) => $query->where('brand_id', $brandId))
            ->when($request->string('provider')->toString(), fn (Builder $query, string $provider) => $query->where('provider', $provider))
            ->when($request->string('model')->toString(), fn (Builder $query, string $model) => $query->where('model', $model))
            ->when($request->string('purpose')->toString(), fn (Builder $query, string $purpose) => $query->where('purpose', $purpose))
            ->when($request->string('status')->toString(), fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->date('from'), fn (Builder $query, $date) => $query->where('created_at', '>=', $date->startOfDay()))
            ->when($request->date('to'), fn (Builder $query, $date) => $query->where('created_at', '<=', $date->endOfDay()));

        return view('admin.platform.ai-runtime-monitor', [
            'accounts' => Account::query()->orderBy('name')->get(),
            'brands' => Brand::query()->orderBy('name')->get(),
            'requests' => (clone $base)->with(['account', 'brand', 'user'])->latest('created_at')->paginate(30)->withQueryString(),
            'summary' => [
                'total' => (clone $base)->count(),
                'completed' => (clone $base)->where('status', 'completed')->count(),
                'failed' => (clone $base)->where('status', 'failed')->count(),
                'tokens' => (int) (clone $base)->sum('total_tokens'),
                'credits' => (int) (clone $base)->sum('credits_charged'),
                'estimated_cost' => (float) (clone $base)->sum('estimated_cost'),
                'average_latency' => round((float) (clone $base)->whereNotNull('latency_ms')->avg('latency_ms')),
            ],
            'byProvider' => (clone $base)
                ->select('provider', DB::raw('count(*) as requests_count'), DB::raw('sum(estimated_cost) as estimated_cost_sum'), DB::raw('sum(total_tokens) as total_tokens_sum'))
                ->groupBy('provider')
                ->orderByDesc('requests_count')
                ->get(),
            'purposes' => LlmRequest::PURPOSES,
            'statuses' => LlmRequest::STATUSES,
        ]);
    }
}
