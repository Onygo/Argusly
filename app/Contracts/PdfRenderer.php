<?php

namespace App\Contracts;

interface PdfRenderer
{
    /**
     * @param array<string,mixed> $data
     */
    public function renderInvoice(array $data): string;
}
