<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Report;
use App\Models\User;
use App\Services\ExecutiveReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ExecutiveReportService $reports,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        return view('app.reports.index', [
            'account' => $account,
            'brand' => $brand,
            'types' => Report::TYPES,
            'reports' => $reports->reportsForTenant($account, $brand),
        ]);
    }

    public function store(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ExecutiveReportService $reports,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(Report::TYPES)],
        ]);

        $report = $reports->generate($account, $brand, $user, $validated['type']);

        return redirect()->route('app.reports.show', $report)->with('status', 'Report generated.');
    }

    public function show(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ExecutiveReportService $reports,
        Report $report,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        return view('app.reports.show', [
            'account' => $account,
            'brand' => $brand,
            'report' => $reports->findForTenant($account, $brand, $report->id),
        ]);
    }
}
