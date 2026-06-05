<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class PrepareOpportunityExecutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'string', 'in:manual,semi_autonomous,autonomous'],
            'run_inline' => ['nullable', 'boolean'],
        ];
    }
}
