<?php

namespace App\Http\Controllers\Admin;

use App\DTO\QueryIntent\QueryIntentInput;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DebugQueryIntentRequest;
use App\Models\QueryIntentClassification;
use App\Services\QueryIntent\QueryIntentIntelligenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminQueryIntentController extends Controller
{
    public function index(Request $request): View
    {
        $classifications = QueryIntentClassification::query()
            ->with(['workspace:id,name,display_name', 'site:id,name'])
            ->orderByDesc('classified_at')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.query-intent.index', [
            'classifications' => $classifications,
            'result' => session('query_intent_result'),
            'input' => session('query_intent_input', []),
        ]);
    }

    public function debug(DebugQueryIntentRequest $request, QueryIntentIntelligenceService $service): RedirectResponse
    {
        $data = $request->validated();
        $input = new QueryIntentInput(
            title: $data['title'] ?? null,
            query: $data['query'] ?? null,
            text: $data['text'],
            locale: $data['locale'] ?? null,
            sourceType: $data['source_type'] ?? 'admin_debug',
            sourceKey: $data['source_key'] ?? null,
        );

        $result = $request->boolean('persist')
            ? $service->classifyAndPersist($input)->toArray()
            : $service->classify($input)->toArray();

        return redirect()
            ->route('admin.query-intent.index')
            ->with('query_intent_result', $result)
            ->with('query_intent_input', $data)
            ->with('status', $request->boolean('persist') ? 'Query intent classified and persisted.' : 'Query intent classified.');
    }
}
