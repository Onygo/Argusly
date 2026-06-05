<?php

namespace App\Http\Requests\Api\V1\Headless;

use Illuminate\Foundation\Http\FormRequest;

class CreateWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'content_destination_id' => ['nullable', 'uuid'],
            'target_url' => ['required', 'url', 'max:2048'],
            'secret' => ['required', 'string', 'min:16', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
