<?php

namespace App\Http\Requests\App;

use App\Models\Brief;
use Illuminate\Foundation\Http\FormRequest;

class RejectBriefSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $brief = $this->route('brief');
        $user = $this->user();

        return $brief instanceof Brief
            && $user !== null
            && $user->can('rejectSuggestion', $brief);
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
