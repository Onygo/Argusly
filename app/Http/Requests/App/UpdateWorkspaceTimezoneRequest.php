<?php

namespace App\Http\Requests\App;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceTimezoneRequest extends FormRequest
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
            'reporting_timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $timezone = trim((string) $this->input('reporting_timezone', ''));

        $this->merge([
            'reporting_timezone' => $timezone === '' ? null : $timezone,
        ]);
    }
}
