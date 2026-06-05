<?php

namespace App\Http\Requests\App;

use App\Models\ResearchProject;
use Illuminate\Foundation\Http\FormRequest;

class StartResearchProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $project instanceof ResearchProject
            && $user !== null
            && $user->can('run', $project);
    }

    public function rules(): array
    {
        return [
            'force' => ['nullable', 'boolean'],
        ];
    }
}
