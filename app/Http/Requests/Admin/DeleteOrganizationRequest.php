<?php

namespace App\Http\Requests\Admin;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DeleteOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirmation_name' => ['required', 'string'],
            'force_delete' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Organization $organization */
            $organization = $this->route('organization');

            $confirmationName = trim((string) $this->input('confirmation_name'));
            $organizationName = trim((string) $organization->name);

            if (strcasecmp($confirmationName, $organizationName) !== 0) {
                $validator->errors()->add(
                    'confirmation_name',
                    'The organization name does not match. Please type the exact organization name to confirm deletion.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'confirmation_name.required' => 'Please type the organization name to confirm deletion.',
        ];
    }
}
