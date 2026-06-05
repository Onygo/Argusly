<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => ['nullable', Rule::in(['json', 'html', 'markdown', 'text'])],
        ];
    }
}
