<?php

namespace App\Http\Requests\App;

use App\Models\Brief;

class EstimateDraftComparisonRequest extends AbstractDraftComparisonSelectionRequest
{
    public function authorize(): bool
    {
        $brief = $this->route('brief');
        $user = $this->user();

        return $brief instanceof Brief
            && $user !== null
            && $user->can('generateDraft', $brief);
    }
}
