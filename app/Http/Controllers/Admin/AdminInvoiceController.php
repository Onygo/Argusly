<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdminInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'organization_id' => (string) $request->query('organization_id', ''),
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
            'q' => trim((string) $request->query('q', '')),
        ];

        $query = Invoice::query()->with('organization')->latest('issued_at');

        if ($filters['organization_id'] !== '' && ctype_digit($filters['organization_id'])) {
            $query->where('organization_id', (int) $filters['organization_id']);
        }

        if ($filters['type'] !== '' && in_array($filters['type'], ['subscription', 'credit_pack'], true)) {
            $query->where('type', $filters['type']);
        }

        if ($filters['status'] !== '' && in_array($filters['status'], ['issued', 'refunded'], true)) {
            $query->where('status', $filters['status']);
        }

        if ($filters['q'] !== '') {
            $query->where(function ($builder) use ($filters) {
                $builder->where('number', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('billing_company_name', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('refund_reference', 'like', '%' . $filters['q'] . '%');
            });
        }

        $invoices = $query->paginate(20)->withQueryString();

        return view('admin.invoices.index', [
            'invoices' => $invoices,
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function download(Invoice $invoice)
    {
        if (! $invoice->pdf_path || ! Storage::disk('local')->exists($invoice->pdf_path)) {
            abort(404, 'Invoice document not found.');
        }

        return Storage::disk('local')->download($invoice->pdf_path, $invoice->number . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function preview(Invoice $invoice, InvoiceService $invoiceService)
    {
        $bytes = $invoiceService->renderPdfBytes($invoice);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $invoice->number . '.pdf"',
        ]);
    }

    public function markRefunded(Request $request, Invoice $invoice, InvoiceService $invoiceService): RedirectResponse
    {
        $data = $request->validate([
            'refund_reference' => ['required', 'string', 'max:128'],
        ]);

        $invoiceService->markRefunded($invoice, (string) $data['refund_reference']);

        return back()->with('status', 'Invoice marked as refunded.');
    }
}
