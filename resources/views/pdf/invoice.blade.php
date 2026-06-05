<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->number }}</title>
    @include('pdf.partials._styles')
</head>
<body>
    @include('pdf.partials._header', ['pdf' => $pdf])
    @include('pdf.partials._items_table', ['pdf' => $pdf])
    @include('pdf.partials._totals', ['pdf' => $pdf])
    @include('pdf.partials._footer', ['pdf' => $pdf])
</body>
</html>
