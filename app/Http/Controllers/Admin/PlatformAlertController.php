<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SignalAlert;
use App\Services\AlertService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformAlertController extends Controller
{
    public function index(Request $request, AlertService $alerts): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SignalAlert::STATUSES)],
            'severity' => ['nullable', Rule::in(SignalAlert::SEVERITIES)],
        ]);

        return view('admin.platform.alerts', [
            'alerts' => $alerts->paginatedForPlatform($filters),
            'filters' => $filters,
            'statuses' => SignalAlert::STATUSES,
            'severities' => SignalAlert::SEVERITIES,
            'stats' => $alerts->statistics(),
        ]);
    }

    public function acknowledge(Request $request, SignalAlert $alert, AlertService $alerts): RedirectResponse
    {
        $alerts->acknowledge($alert, $request->user());

        return back()->with('status', 'Alert acknowledged.');
    }

    public function resolve(Request $request, SignalAlert $alert, AlertService $alerts): RedirectResponse
    {
        $alerts->resolve($alert, $request->user());

        return back()->with('status', 'Alert resolved.');
    }
}
