<?php

namespace App\Support\Intelligence;

interface EvidenceCollector
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function collect(mixed $source = null, array $context = []): EvidenceBag;
}
