<?php

namespace App\Http\Requests\App;

use App\Models\ResearchProject;
use Illuminate\Foundation\Http\FormRequest;

class CreateResearchProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', ResearchProject::class);
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['nullable', 'uuid', 'exists:workspaces,id'],
            'name' => ['required', 'string', 'max:191'],
            'target_keywords' => ['nullable', 'array'],
            'target_keywords.*' => ['string', 'max:120'],
            'source_urls' => ['required', 'array', 'min:1'],
            'source_urls.*' => ['required', 'string', 'max:2048'],
            'brief_id' => ['nullable', 'uuid', 'exists:briefs,id'],
            'client_site_id' => ['nullable', 'uuid', 'exists:client_sites,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'target_keywords' => $this->normalizeListInput($this->input('target_keywords')),
            'source_urls' => $this->normalizeListInput($this->input('source_urls')),
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function normalizeListInput(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\n,;]+/', $value) ?: [];
        }

        return collect((array) $value)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }
}
