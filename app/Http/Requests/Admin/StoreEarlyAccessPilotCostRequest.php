<?php

namespace App\Http\Requests\Admin;

use App\Models\EarlyAccessPilotCost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEarlyAccessPilotCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(array_keys(EarlyAccessPilotCost::categoryOptions()))],
            'description' => ['required', 'string', 'max:255'],
            'amount_eur' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'incurred_on' => ['nullable', 'date'],
        ];
    }
}
