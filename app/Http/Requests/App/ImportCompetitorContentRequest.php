<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class ImportCompetitorContentRequest extends FormRequest
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
            'site_competitor_id' => ['required', 'integer', 'exists:site_competitors,id'],
            'url' => ['nullable', 'string', 'max:2048'],
            'title' => ['required', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:1000'],
            'content_excerpt' => ['required', 'string', 'max:12000'],
        ];
    }
}
