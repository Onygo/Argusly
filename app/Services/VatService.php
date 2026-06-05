<?php

namespace App\Services;

class VatService
{
    private const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
        'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    public function resolve(string $countryCode, string $vatNumber): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $vatNumber = strtoupper(trim($vatNumber));

        if ($countryCode === '' || $countryCode === 'NL') {
            return ['rate' => 21.00, 'type' => 'nl_vat', 'reverse_charge' => false];
        }

        if (in_array($countryCode, self::EU_COUNTRIES, true)) {
            if ($vatNumber !== '') {
                return ['rate' => 0.00, 'type' => 'eu_reverse_charge', 'reverse_charge' => true];
            }

            return ['rate' => 21.00, 'type' => 'eu_no_vat_number', 'reverse_charge' => false];
        }

        return ['rate' => 0.00, 'type' => 'export_outside_eu', 'reverse_charge' => false];
    }
}
