<?php

namespace App\Http\Requests\Api\V1\Headless;

use App\Services\Api\ApiScopes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateApiKeyRequest extends FormRequest
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
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiScopes::all())],
            'expires_at' => ['nullable', 'date'],
        ];
    }
}
