<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;

class StartSeoAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content_destination_id' => ['nullable', 'uuid'],
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ];
    }
}
