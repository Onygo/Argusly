<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brief_updates' => ['nullable', 'boolean'],
            'draft_ready' => ['nullable', 'boolean'],
            'weekly_summary' => ['nullable', 'boolean'],
        ];
    }
}
