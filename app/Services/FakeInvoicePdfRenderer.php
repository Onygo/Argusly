<?php

namespace App\Services;

use App\Contracts\PdfRenderer;

class FakeInvoicePdfRenderer implements PdfRenderer
{
    /**
     * @param array<string,mixed> $data
     */
    public function renderInvoice(array $data): string
    {
        unset($data);

        return "%PDF-1.4\n"
            . "1 0 obj\n"
            . "<< /Type /Catalog >>\n"
            . "endobj\n"
            . "trailer\n"
            . "<< /Root 1 0 R >>\n"
            . "%%EOF\n";
    }
}
