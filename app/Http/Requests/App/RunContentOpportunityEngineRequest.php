<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class RunContentOpportunityEngineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            'client_site_id' => ['nullable', 'uuid', 'exists:client_sites,id'],
            'run_inline' => ['nullable', 'boolean'],
        ];
    }
}
