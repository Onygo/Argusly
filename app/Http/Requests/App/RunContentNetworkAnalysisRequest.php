<?php

namespace App\Http\Requests\App;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

class RunContentNetworkAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->route('workspace');
        $user = $this->user();

        return $workspace instanceof Workspace
            && $user !== null
            && $user->can('runContentNetworkAnalysis', $workspace);
    }

    public function rules(): array
    {
        return [
            'force' => ['nullable', 'boolean'],
        ];
    }
}
