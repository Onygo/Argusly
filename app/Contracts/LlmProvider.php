<?php

namespace App\Contracts;

use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;

interface LlmProvider
{
    public function name(): string;

    public function generateText(LlmRequest $request): LlmResponse;

    public function generateJson(LlmRequest $request, array|string|null $schemaOrExpectation = null): LlmResponse;
}
