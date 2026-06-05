<?php

namespace App\Services;

use App\Contracts\PdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;

class DompdfInvoicePdfRenderer implements PdfRenderer
{
    /**
     * @param array<string,mixed> $data
     */
    public function renderInvoice(array $data): string
    {
        Pdf::setOption([
            'isHtml5ParserEnabled' => true,
            'dpi' => 96,
            'defaultFont' => 'Arial',
        ]);

        $pdf = Pdf::loadView('pdf.invoice', $data);
        $pdf->setPaper('a4');

        return $pdf->output();
    }
}
