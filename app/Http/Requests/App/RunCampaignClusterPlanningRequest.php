<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class RunCampaignClusterPlanningRequest extends FormRequest
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
            'run_inline' => ['nullable', 'boolean'],
        ];
    }
}
