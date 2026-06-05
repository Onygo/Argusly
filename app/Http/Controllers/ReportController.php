<?php

namespace App\Http\Controllers;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Models\Report;
use App\Models\User;
use App\Services\ExecutiveReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
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

    public function executive(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ExecutiveReportService $reports,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);

        return view('app.reports.executive', [
            'account' => $account,
            'brand' => $brand,
            'dashboard' => $reports->dashboard($account, $brand),
            'types' => Report::TYPES,
        ]);
    }

    public function exportPdf(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ExecutiveReportService $reports,
        Report $report,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);
        $report = $reports->findForTenant($account, $brand, $report->id);

        return response($reports->exportPdf($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$report->uuid.'.pdf"',
        ]);
    }

    public function exportPowerPoint(
        Request $request,
        CurrentAccountContract $currentAccount,
        CurrentBrandContract $currentBrand,
        ExecutiveReportService $reports,
        Report $report,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $account = $currentAccount->get($user) ?? abort(403);
        $brand = $currentBrand->get($user);
        $report = $reports->findForTenant($account, $brand, $report->id);

        return response($reports->exportPowerPoint($report), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'Content-Disposition' => 'attachment; filename="'.$report->uuid.'.pptx"',
        ]);
    }
}
