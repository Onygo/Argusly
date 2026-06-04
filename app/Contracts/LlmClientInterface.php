<?php

namespace App\Contracts;

use App\Data\Llm\LlmRequest;
use App\Data\Llm\LlmResponse;
use Traversable;

interface LlmClientInterface
{
    public function chat(LlmRequest $request): LlmResponse;

    public function generate(LlmRequest $request): LlmResponse;

    /**
     * @return Traversable<int, string>
     */
    public function stream(LlmRequest $request): Traversable;

    public function embed(LlmRequest $request): LlmResponse;

    public function vision(LlmRequest $request): LlmResponse;
}
