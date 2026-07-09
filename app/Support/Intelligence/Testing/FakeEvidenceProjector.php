<?php

namespace App\Support\Intelligence\Testing;

use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceNormalizer;
use App\Support\Intelligence\EvidenceProjector;
use App\Support\Intelligence\EvidenceReference;

class FakeEvidenceProjector implements EvidenceProjector
{
    private EvidenceBag $bag;

    public function __construct(
        private readonly EvidenceNormalizer $normalizer = new EvidenceNormalizer(),
    ) {
        $this->bag = EvidenceBag::empty();
    }

    public function projectReference(EvidenceReference $reference): static
    {
        $this->bag = EvidenceBag::merge($this->bag, new EvidenceBag([$reference]));

        return $this;
    }

    public function projectBag(EvidenceBag $bag): static
    {
        $this->bag = EvidenceBag::merge($this->bag, $bag);

        return $this;
    }

    public function project(mixed $evidence): static
    {
        return $this->projectBag($this->normalizer->normalize($evidence));
    }

    public function bag(): EvidenceBag
    {
        return $this->bag;
    }

    /**
     * @return array<string, mixed>
     */
    public function evidence(): array
    {
        return $this->bag->toArray();
    }
}
