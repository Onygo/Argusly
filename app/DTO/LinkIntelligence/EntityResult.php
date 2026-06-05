<?php

namespace App\DTO\LinkIntelligence;

class EntityResult
{
    /**
     * @param array<int, array{name:string,type:string,confidence:float}> $entities
     */
    public function __construct(
        public readonly array $entities,
    ) {}
}
