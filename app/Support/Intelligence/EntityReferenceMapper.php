<?php

namespace App\Support\Intelligence;

interface EntityReferenceMapper
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function map(CanonicalEntityReference $reference, array $context = []): CanonicalEntityReference;
}
