<?php

namespace App\Http\Requests\App;

use App\Models\DraftComparison;
use Illuminate\Foundation\Http\FormRequest;

class SelectComparisonWinnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $comparison = $this->route('comparison');
        $user = $this->user();

        return $comparison instanceof DraftComparison
            && $user !== null
            && $user->can('selectWinner', $comparison);
    }

    public function rules(): array
    {
        return [
            'draft_id' => ['required', 'uuid'],
        ];
    }
}
