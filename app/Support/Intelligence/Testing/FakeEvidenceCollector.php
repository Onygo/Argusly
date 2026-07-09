<?php

namespace App\Support\Intelligence\Testing;

use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceCollector;
use App\Support\Intelligence\EvidenceNormalizer;

class FakeEvidenceCollector implements EvidenceCollector
{
    /**
     * @var array<int, mixed>
     */
    private array $sources = [];

    /**
     * @param  iterable<int, mixed>  $sources
     */
    public function __construct(
        iterable $sources = [],
        private readonly EvidenceNormalizer $normalizer = new EvidenceNormalizer(),
    ) {
        foreach ($sources as $source) {
            $this->sources[] = $source;
        }
    }

    public function add(mixed $source): static
    {
        $this->sources[] = $source;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function collect(mixed $source = null, array $context = []): EvidenceBag
    {
        $sources = $this->sources;

        if ($source !== null) {
            $sources[] = $source;
        }

        return $this->normalizer->normalizeMany($sources, $context);
    }
}
