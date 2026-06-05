<div class="section">
    <table class="header-table">
        <tr>
            <td class="seller-col">
                <div style="margin-bottom: 10px;">
                    @include('components.invoices.logo', ['pdf' => $pdf])
                </div>
                <h1 class="title">Invoice</h1>
                <div class="billed-wrap">
                    <div class="billed-title">Billed to</div>
                    @foreach($pdf->billedToLines as $index => $line)
                        <div class="text-line{{ $index === 0 ? ' billed-name' : '' }}">{{ $line }}</div>
                    @endforeach
                </div>
            </td>
            <td class="meta-col" style="font-family: Arial, sans-serif !important;">
                <table class="meta-table" style="font-family: Arial, sans-serif !important;">
                    @foreach($pdf->metaFields as $meta)
                        <tr style="font-family: Arial, sans-serif !important;">
                            <td class="meta-label" style="font-family: Arial, sans-serif !important;">
                                <span style="font-family: Arial, sans-serif !important;">{{ $meta['label'] }}</span>
                            </td>
                            <td class="meta-value" style="font-family: Arial, sans-serif !important;">
                                <span style="font-family: Arial, sans-serif !important;">{{ $meta['value'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
</div>
