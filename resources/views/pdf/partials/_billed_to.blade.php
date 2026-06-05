<div class="section">
    <table class="billed-table">
        <tr>
            <td>
                <div class="billed-title">Billed to</div>
                @foreach($pdf->billedToLines as $index => $line)
                    <div class="text-line{{ $index === 0 ? ' billed-name' : '' }}">{{ $line }}</div>
                @endforeach
            </td>
        </tr>
    </table>
</div>
