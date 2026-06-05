<?php

namespace App\Http\Requests\App;

use App\Enums\DraftImprovementAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImproveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $rawAction = $this->input('action', $this->input('section'));
        $normalized = is_string($rawAction)
            ? DraftImprovementAction::fromInput($rawAction)?->value
            : null;

        if ($normalized !== null) {
            $this->merge(['action' => $normalized]);
        }
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(DraftImprovementAction::values())],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Choose a draft improvement action.',
            'action.in' => 'Choose a valid draft improvement action.',
        ];
    }
}
