<?php

namespace App\Services\Llm\Data;

class LlmMessage
{
    public function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {
    }
}
