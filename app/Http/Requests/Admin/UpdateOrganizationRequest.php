<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('organization')?->id;

        return [
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', Rule::unique('organizations', 'slug')->ignore($organizationId)],
            'custom_domain' => ['nullable', 'string', 'max:190'],
            'webhook_url' => ['nullable', 'string', 'max:255'],
            'api_enabled' => ['nullable', 'boolean'],
        ];
    }
}
