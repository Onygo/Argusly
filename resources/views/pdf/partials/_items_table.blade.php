<table class="items-table section">
    <thead>
        <tr>
            <th class="description">Description</th>
            <th class="num">Qty</th>
            <th class="num">Unit (net)</th>
            <th class="num">Subtotal (net)</th>
            <th class="num">VAT</th>
            <th class="num">Total (gross)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($pdf->items as $item)
            <tr>
                <td class="description">{{ $item['description'] }}</td>
                <td class="num">{{ $item['qty'] }}</td>
                <td class="num">{{ $item['unit_net'] }}</td>
                <td class="num">{{ $item['line_net'] }}</td>
                <td class="num">{{ $item['vat_label'] }}</td>
                <td class="num">{{ $item['line_gross'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
