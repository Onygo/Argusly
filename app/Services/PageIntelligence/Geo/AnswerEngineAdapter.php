<?php

namespace App\Services\PageIntelligence\Geo;

interface AnswerEngineAdapter
{
    /**
     * @param array<string,mixed> $parameters
     * @return iterable<array<string,mixed>>
     */
    public function observe(array $parameters): iterable;
}
