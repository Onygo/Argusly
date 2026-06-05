<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class GenerateUrlBriefSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content_source_id' => ['nullable', 'uuid', 'required_without:source_url'],
            'source_url' => ['nullable', 'url', 'max:2048', 'required_without:content_source_id'],
            'output_mode' => ['required', 'in:brief_only,brief_keywords,brief_chain'],
            'extraction_mode' => ['nullable', 'in:default,alternative'],
            'manual_source_notes' => ['nullable', 'string', 'max:20000'],
            'locale' => ['nullable', 'string', 'max:8'],
            'force_new' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $sourceUrl = trim((string) $this->input('source_url', ''));

        if ($sourceUrl !== '' && ! preg_match('#^https?://#i', $sourceUrl)) {
            $this->merge([
                'source_url' => 'https://' . ltrim($sourceUrl, '/'),
            ]);
        }
    }
}
