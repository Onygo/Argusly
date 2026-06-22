<?php

namespace App\Http\Requests\Admin;

use App\Enums\SupportedLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperadmin();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', 'alpha_dash:ascii', Rule::unique('organizations', 'slug')],
            'status' => ['required', Rule::in(['active', 'pending', 'on_hold'])],
            'access_tier' => ['required', Rule::in(['paid', 'early_bird', 'trial', 'free'])],
            'billing_email' => ['nullable', 'email', 'max:190'],

            'owner_name' => ['required', 'string', 'max:190'],
            'owner_email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'owner_password' => ['required', 'confirmed', Password::defaults()],
            'owner_active' => ['nullable', 'boolean'],

            'create_workspace' => ['nullable', 'boolean', 'accepted_if:create_site,1'],
            'workspace_name' => ['required_if:create_workspace,1', 'nullable', 'string', 'max:190'],
            'default_content_language' => ['required_if:create_workspace,1', 'nullable', Rule::in(SupportedLanguage::values())],

            'create_site' => ['nullable', 'boolean'],
            'site_name' => ['required_if:create_site,1', 'nullable', 'string', 'max:190'],
            'site_url' => ['required_if:create_site,1', 'nullable', 'url', 'max:255'],
            'site_type' => ['required_if:create_site,1', 'nullable', Rule::in(['wordpress', 'laravel'])],
            'allowed_domains' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'owner_name' => 'owner name',
            'owner_email' => 'owner email',
            'owner_password' => 'owner password',
            'workspace_name' => 'workspace name',
            'site_name' => 'site name',
            'site_url' => 'site URL',
            'site_type' => 'site type',
            'allowed_domains' => 'allowed domains',
        ];
    }
}
