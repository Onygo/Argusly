<?php

namespace App\Http\Requests\App;

use App\Models\EnrichmentRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrandWizardInputRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'input_type' => ['required', 'in:text,website_url,guided'],

            // For text input
            'pasted_text' => ['required_if:input_type,text', 'nullable', 'string', 'max:50000'],

            // For website URL
            'website_url' => ['required_if:input_type,website_url', 'nullable', 'url', 'max:2048'],

            // For guided input
            'company_name' => ['required_if:input_type,guided', 'nullable', 'string', 'max:255'],
            'what_you_do' => ['required_if:input_type,guided', 'nullable', 'string', 'max:2000'],
            'target_audience' => ['nullable', 'string', 'max:2000'],
            'tone_description' => ['nullable', 'string', 'max:1000'],

            // Section selection
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', Rule::in(EnrichmentRun::BRAND_SECTIONS)],

            // Generation mode
            'generation_mode' => ['nullable', Rule::in([
                EnrichmentRun::GENERATION_MODE_FULL,
                EnrichmentRun::GENERATION_MODE_MISSING_ONLY,
                EnrichmentRun::GENERATION_MODE_REGENERATE,
            ])],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pasted_text.required_if' => 'Please paste your text content when using text input.',
            'website_url.required_if' => 'Please enter a website URL when using URL input.',
            'website_url.url' => 'Please enter a valid website URL.',
            'company_name.required_if' => 'Please enter your company name when using guided input.',
            'what_you_do.required_if' => 'Please describe what your company does when using guided input.',
            'sections.required' => 'Please select at least one section to generate.',
            'sections.min' => 'Please select at least one section to generate.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'input_type' => 'input method',
            'pasted_text' => 'pasted text',
            'website_url' => 'website URL',
            'company_name' => 'company name',
            'what_you_do' => 'company description',
            'target_audience' => 'target audience',
            'tone_description' => 'tone description',
        ];
    }
}
