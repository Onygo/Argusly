<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class StoreExecutionFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'asset_id' => ['nullable', 'uuid', 'exists:agentic_marketing_execution_assets,id'],
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}
