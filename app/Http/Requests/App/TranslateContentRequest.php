<?php

namespace App\Http\Requests\App;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TranslateContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $content = $this->route('content');

        return $user !== null
            && $content instanceof Content
            && $user->can('update', $content);
    }

    public function rules(): array
    {
        return [
            'target_locale' => ['required', 'string', Rule::in(SupportedLanguage::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'target_locale' => SupportedLanguage::normalizeLocale($this->input('target_locale')),
        ]);
    }

    public function targetLocale(): string
    {
        return (string) $this->validated('target_locale');
    }
}
