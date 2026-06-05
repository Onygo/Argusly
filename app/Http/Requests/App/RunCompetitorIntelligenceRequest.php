<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class RunCompetitorIntelligenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'site_competitor_id' => ['nullable', 'integer', 'exists:site_competitors,id'],
            'run_inline' => ['nullable', 'boolean'],
        ];
    }
}
