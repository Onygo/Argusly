<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AddExistingPilotUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin === true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'workspace_id' => ['nullable', 'uuid', 'exists:workspaces,id'],
            'ends_at' => ['nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email', ''))),
            'workspace_id' => trim((string) $this->input('workspace_id', '')) ?: null,
            'ends_at' => trim((string) $this->input('ends_at', '')) ?: null,
            'notes' => trim((string) $this->input('notes', '')),
        ]);
    }
}
