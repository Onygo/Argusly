<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DebugQueryIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin-area-manage-approvals') ?? false;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'query' => ['nullable', 'string', 'max:255'],
            'text' => ['required', 'string', 'max:12000'],
            'locale' => ['nullable', 'string', 'max:20'],
            'source_type' => ['nullable', 'string', 'max:80'],
            'source_key' => ['nullable', 'string', 'max:191'],
            'persist' => ['nullable', 'boolean'],
        ];
    }
}
