<div class="totals-wrap section">
    <table class="totals-table">
        <tr>
            <td class="totals-label">Subtotal (net)</td>
            <td class="num">{{ $pdf->subtotalNet }}</td>
        </tr>
        <tr>
            <td class="totals-label">VAT ({{ $pdf->vatLabel }})</td>
            <td class="num">{{ $pdf->vatAmount }}</td>
        </tr>
        <tr class="totals-total-row">
            <td class="totals-label">Total (gross)</td>
            <td class="num">{{ $pdf->totalGross }}</td>
        </tr>
    </table>
</div>
