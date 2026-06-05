<?php

namespace App\Http\Requests\App;

use App\Enums\SupportedLanguage;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceLanguageSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = Workspace::query()
            ->where('organization_id', (int) ($this->user()?->organization_id ?? 0))
            ->first();

        return $workspace instanceof Workspace
            && $this->user() !== null
            && $this->user()->can('updateName', $workspace);
    }

    public function rules(): array
    {
        return [
            'default_content_language' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'enabled_content_languages' => ['required', 'array', 'min:1'],
            'enabled_content_languages.*' => ['required', 'string', Rule::in(SupportedLanguage::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $enabled = collect((array) $this->input('enabled_content_languages', []))
            ->map(fn (mixed $value): string => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $default = strtolower(trim((string) $this->input('default_content_language', '')));

        if ($default !== '' && ! in_array($default, $enabled, true)) {
            $enabled[] = $default;
        }

        $this->merge([
            'default_content_language' => $default,
            'enabled_content_languages' => $enabled,
        ]);
    }
}
