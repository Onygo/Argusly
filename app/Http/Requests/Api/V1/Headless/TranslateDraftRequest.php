<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;

class TranslateDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_language' => ['required', 'string', 'max:10'],
            'model' => ['nullable', 'string', 'max:120'],
        ];
    }
}
