<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class SaveUrlBriefSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content_source_id' => ['required', 'uuid'],
            'destination_mode' => ['nullable', 'in:connected,api_only,hybrid'],
            'site_id' => ['nullable', 'string', 'required_if:destination_mode,connected'],
            'content_destination_id' => ['nullable', 'string'],
            'manual_source_notes' => ['nullable', 'string', 'max:20000'],
            'next_action' => ['nullable', 'in:save,generate_draft,create_chain'],
        ];
    }
}
