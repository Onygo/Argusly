<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'target_url' => ['sometimes', 'url', 'max:2048'],
            'secret' => ['sometimes', 'string', 'min:16', 'max:500'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
