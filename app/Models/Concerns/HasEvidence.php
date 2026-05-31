<?php

namespace App\Models\Concerns;

use App\Models\EvidenceItem;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasEvidence
{
    /**
     * @return MorphMany<EvidenceItem, $this>
     */
    public function evidenceItems(): MorphMany
    {
        return $this->morphMany(EvidenceItem::class, 'subject')
            ->latest('captured_at')
            ->latest();
    }
}
