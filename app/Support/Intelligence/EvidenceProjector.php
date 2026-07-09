<?php

namespace App\Support\Intelligence;

interface EvidenceProjector
{
    public function projectReference(EvidenceReference $reference): static;

    public function projectBag(EvidenceBag $bag): static;

    public function project(mixed $evidence): static;

    public function bag(): EvidenceBag;

    /**
     * @return array<string, mixed>
     */
    public function evidence(): array;
}
