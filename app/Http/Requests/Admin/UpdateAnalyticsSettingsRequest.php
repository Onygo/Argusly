<?php

namespace App\Http\Requests\Admin;

use App\Services\Analytics\AnalyticsSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateAnalyticsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('admin-area-superadmin');
    }

    public function rules(): array
    {
        $provider = $this->input('analytics_provider');

        return [
            'analytics_enabled' => ['nullable', 'boolean'],
            'analytics_public_only' => ['nullable', 'boolean'],
            'analytics_provider' => [
                'nullable',
                'string',
                Rule::in(array_keys(AnalyticsSettingsService::PROVIDERS)),
            ],
            'analytics_measurement_id' => [
                'nullable',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($provider) {
                    if ($provider === AnalyticsSettingsService::PROVIDER_GOOGLE_ANALYTICS && $value) {
                        if (! AnalyticsSettingsService::isValidMeasurementId($value)) {
                            $fail('The measurement ID must be in the format G-XXXXXXXXXX.');
                        }
                    }
                },
            ],
            'analytics_container_id' => [
                'nullable',
                'string',
                'max:50',
                function ($attribute, $value, $fail) use ($provider) {
                    if ($provider === AnalyticsSettingsService::PROVIDER_GOOGLE_TAG_MANAGER && $value) {
                        if (! AnalyticsSettingsService::isValidContainerId($value)) {
                            $fail('The container ID must be in the format GTM-XXXXXXX.');
                        }
                    }
                },
            ],
            'analytics_custom_head_script' => [
                'nullable',
                'string',
                'max:10000',
                function ($attribute, $value, $fail) {
                    if ($value && ! Gate::allows('admin-area-superadmin')) {
                        $fail('Only superadmins can set custom head scripts.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'analytics_provider.in' => 'Please select a valid tracking provider.',
            'analytics_measurement_id.max' => 'The measurement ID is too long.',
            'analytics_container_id.max' => 'The container ID is too long.',
            'analytics_custom_head_script.max' => 'The custom script is too long (max 10,000 characters).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'analytics_enabled' => $this->boolean('analytics_enabled'),
            'analytics_public_only' => $this->boolean('analytics_public_only'),
        ]);
    }
}
