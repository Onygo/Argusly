<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationLegalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['nullable', 'string', 'max:200'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'vat_id' => ['nullable', 'string', 'max:64'],
            'billing_address_line1' => ['nullable', 'string', 'max:255'],
            'billing_address_line2' => ['nullable', 'string', 'max:255'],
            'billing_postal_code' => ['nullable', 'string', 'max:64'],
            'billing_city' => ['nullable', 'string', 'max:128'],
            'billing_country_code' => ['nullable', 'string', 'size:2'],
        ];
    }
}

