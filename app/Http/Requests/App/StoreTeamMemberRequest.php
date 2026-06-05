<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'public_profile_url' => ['nullable', 'url', 'max:2048'],
            'bio_source_text' => ['nullable', 'string', 'max:12000'],
            'expertise' => ['nullable', 'string', 'max:2000'],
            'writing_perspective' => ['nullable', 'string', 'max:2000'],
            'personality_traits' => ['nullable', 'string', 'max:2000'],
            'use_as_writing_persona' => ['nullable', 'boolean'],
            'link_to_real_team_member_later' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Team member name is required.',
            'name.max' => 'Team member name cannot exceed 255 characters.',
        ];
    }
}
