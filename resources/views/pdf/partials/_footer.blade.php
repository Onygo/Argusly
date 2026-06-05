<table class="footer-table">
    <tr>
        <td>
            @if($pdf->notes)
                <div class="text-line"><strong>Notes:</strong> {{ $pdf->notes }}</div>
            @endif
            @if($pdf->terms)
                <div class="text-line"><strong>Terms:</strong> {{ $pdf->terms }}</div>
            @endif
        </td>
        <td class="footer-right">
            @foreach($pdf->sellerLines as $line)
                <div class="text-line">{{ $line }}</div>
            @endforeach
        </td>
    </tr>
</table>
