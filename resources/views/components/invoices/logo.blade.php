@if(! empty($pdf->issuerLogoDataUri))
    <img
        src="{{ $pdf->issuerLogoDataUri }}"
        alt="Argusly logo"
        style="display:block; width:auto; height:28px;"
    >
@else
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr>
            <td style="width: 28px; vertical-align: middle;">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 64 64"
                    width="28"
                    height="28"
                    aria-label="Argusly brand icon"
                    style="display: block;"
                >
                    <rect width="64" height="64" rx="12" fill="#FEF3C7"></rect>
                    <g stroke="#7C2D12" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" fill="none">
                        <path d="M32 16 47 24 32 32 17 24 32 16Z"></path>
                        <path d="M17 32 32 40 47 32"></path>
                        <path d="M17 40 32 48 47 40"></path>
                    </g>
                </svg>
            </td>
            <td style="padding-left: 8px; vertical-align: middle;">
                <span
                    id="invoice-brand-wordmark"
                    style="display: inline-block; color: #111827; font-family: Arial, sans-serif; font-size: 14px; font-weight: 600; line-height: 1;"
                >
                    Argusly
                </span>
            </td>
        </tr>
    </table>
@endif
