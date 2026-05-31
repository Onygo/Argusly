<?php

namespace App\Services;

use App\Models\EvidenceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EvidenceService
{
    /**
     * @param  array{source_id?: int|string|null, evidence_type: string, title?: string|null, url?: string|null, snippet?: string|null, raw_payload?: array<string, mixed>|null, confidence_score?: int|null, captured_at?: mixed}  $attributes
     */
    public function createForSubject(Model $subject, array $attributes): EvidenceItem
    {
        $accountId = $subject->getAttribute('account_id');
        $brandId = $subject->getAttribute('brand_id');

        if ($accountId === null) {
            throw new InvalidArgumentException('Evidence subjects must be tenant scoped.');
        }

        $evidenceType = $attributes['evidence_type'];

        if (! in_array($evidenceType, EvidenceItem::TYPES, true)) {
            throw new InvalidArgumentException("Invalid evidence type [{$evidenceType}].");
        }

        $confidenceScore = $attributes['confidence_score'] ?? null;

        return EvidenceItem::query()->create([
            'account_id' => $accountId,
            'brand_id' => $brandId,
            'source_id' => $attributes['source_id'] ?? null,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'evidence_type' => $evidenceType,
            'title' => $attributes['title'] ?? null,
            'url' => $attributes['url'] ?? null,
            'snippet' => $attributes['snippet'] ?? null,
            'raw_payload' => $attributes['raw_payload'] ?? null,
            'confidence_score' => $confidenceScore === null ? null : max(0, min(100, (int) $confidenceScore)),
            'captured_at' => $attributes['captured_at'] ?? now(),
        ]);
    }

    /**
     * @return Collection<int, EvidenceItem>
     */
    public function copyBetweenSubjects(Model $from, Model $to): Collection
    {
        $from->loadMissing('evidenceItems');

        return $from->evidenceItems
            ->map(fn (EvidenceItem $evidence) => $this->createForSubject($to, [
                'source_id' => $evidence->source_id,
                'evidence_type' => $evidence->evidence_type,
                'title' => $evidence->title,
                'url' => $evidence->url,
                'snippet' => $evidence->snippet,
                'raw_payload' => $evidence->raw_payload,
                'confidence_score' => $evidence->confidence_score,
                'captured_at' => $evidence->captured_at,
            ]));
    }
}
