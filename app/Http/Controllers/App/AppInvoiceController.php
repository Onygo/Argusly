<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AppInvoiceController extends Controller
{
    public function download(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        if (! $user->is_admin && (int) $invoice->organization_id !== (int) $user->organization_id) {
            abort(403);
        }

        if (! $invoice->pdf_path || ! Storage::disk('local')->exists($invoice->pdf_path)) {
            abort(404, 'Invoice document not found.');
        }

        return Storage::disk('local')->download($invoice->pdf_path, $invoice->number . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
