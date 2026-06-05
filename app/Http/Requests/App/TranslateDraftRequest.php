<?php

namespace App\Http\Requests\App;

use App\Enums\SupportedLanguage;
use App\Models\Draft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TranslateDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $draft = $this->route('draft');

        return $user !== null
            && $draft instanceof Draft
            && $user->can('translate', $draft);
    }

    public function rules(): array
    {
        return [
            'target_languages' => ['required', 'array', 'min:1', 'max:5'],
            'target_languages.*' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'model' => ['nullable', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $languages = collect((array) $this->input('target_languages', []))
            ->map(fn (mixed $value): ?string => SupportedLanguage::normalizeLocale((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->merge([
            'target_languages' => $languages,
            'model' => $this->nullableTrim('model'),
        ]);
    }

    private function nullableTrim(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }
}
