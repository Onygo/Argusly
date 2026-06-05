<?php

namespace App\Http\Requests\Api\V1\Headless;

use App\Enums\ContentDestinationEnvironment;
use App\Enums\ContentDestinationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateDestinationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(ContentDestinationType::values())],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'environment' => ['nullable', Rule::in(array_map(fn ($case) => $case->value, ContentDestinationEnvironment::cases()))],
            'config' => ['nullable', 'array'],
            'config.laravel_connector.base_url' => ['nullable', 'url', 'max:2048'],
            'config.laravel_connector.sync_endpoint' => ['nullable', 'string', 'max:255'],
            'config.laravel_connector.site_id' => ['nullable', 'string', 'max:255'],
            'config.laravel_connector.api_key' => ['nullable', 'string', 'max:500'],
            'config.laravel_connector.enabled' => ['nullable', 'boolean'],
            'config.laravel_connector.mode' => ['nullable', Rule::in(['hosted_views', 'headless'])],
            'default_language' => ['nullable', 'string', 'max:10'],
            'default_content_type' => ['nullable', 'string', 'max:64'],
            'export_format' => ['nullable', Rule::in(['json', 'html', 'markdown', 'text'])],
            'tracking_enabled' => ['nullable', 'boolean'],
            'seo_audit_enabled' => ['nullable', 'boolean'],
            'webhook_url' => ['nullable', 'url', 'max:2048'],
            'webhook_secret' => ['nullable', 'string', 'max:500'],
        ];
    }
}
