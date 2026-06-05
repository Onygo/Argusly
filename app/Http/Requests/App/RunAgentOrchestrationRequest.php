<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class RunAgentOrchestrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'uuid'],
            'client_site_id' => ['nullable', 'uuid'],
            'objective_id' => ['nullable', 'uuid'],
            'focus_topic' => ['nullable', 'string', 'max:191'],
            'mode' => ['nullable', 'string', 'in:manual,semi_autonomous,autonomous'],
            'provider_key' => ['nullable', 'string', 'max:120'],
            'run_inline' => ['nullable', 'boolean'],
        ];
    }
}
