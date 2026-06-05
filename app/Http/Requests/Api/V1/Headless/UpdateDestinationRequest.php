<?php

namespace App\Http\Requests\Api\V1\Headless;

use App\Enums\ContentDestinationEnvironment;
use App\Enums\ContentDestinationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(ContentDestinationType::values())],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
            'environment' => ['sometimes', Rule::in(array_map(fn ($case) => $case->value, ContentDestinationEnvironment::cases()))],
            'config' => ['sometimes', 'array'],
            'config.laravel_connector.base_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'config.laravel_connector.sync_endpoint' => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.laravel_connector.site_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'config.laravel_connector.api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config.laravel_connector.enabled' => ['sometimes', 'boolean'],
            'config.laravel_connector.mode' => ['sometimes', 'nullable', Rule::in(['hosted_views', 'headless'])],
            'default_language' => ['sometimes', 'string', 'max:10'],
            'default_content_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'export_format' => ['sometimes', 'nullable', Rule::in(['json', 'html', 'markdown', 'text'])],
            'tracking_enabled' => ['sometimes', 'boolean'],
            'seo_audit_enabled' => ['sometimes', 'boolean'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
