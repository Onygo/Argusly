<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;

class RegenerateDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'requested_max_output_tokens' => ['nullable', 'integer', 'min:128', 'max:64000'],
        ];
    }
}
