<?php

namespace App\Http\Requests\App;

use App\Models\Brief;
use Illuminate\Foundation\Http\FormRequest;

class EnhanceBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brief = $this->route('brief');
        $user = $this->user();

        return $brief instanceof Brief
            && $user !== null
            && $user->can('enhance', $brief);
    }

    public function rules(): array
    {
        return [
            'force' => ['nullable', 'boolean'],
        ];
    }
}
