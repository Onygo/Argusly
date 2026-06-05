<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class PreviewUrlBriefSourceRequest extends FormRequest
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
            'source_url' => ['required', 'url', 'max:2048'],
            'extraction_mode' => ['nullable', 'in:default,alternative'],
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
