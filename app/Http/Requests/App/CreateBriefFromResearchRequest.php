<?php

namespace App\Http\Requests\App;

use App\Models\Brief;
use Illuminate\Foundation\Http\FormRequest;

class CreateBriefFromResearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('createFromResearch', Brief::class);
    }

    public function rules(): array
    {
        return [
            'research_project_id' => ['required', 'uuid', 'exists:research_projects,id'],
            'site_id' => ['nullable', 'uuid', 'exists:client_sites,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'content_type' => ['nullable', 'in:blog,landing,linkedin,email,other'],
            'language' => ['nullable', 'in:nl,en'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'research_project_id' => $this->nullableTrim('research_project_id'),
            'site_id' => $this->nullableTrim('site_id'),
            'title' => $this->nullableTrim('title'),
            'content_type' => $this->nullableTrim('content_type'),
            'language' => $this->nullableTrim('language'),
        ]);
    }

    private function nullableTrim(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }
}
