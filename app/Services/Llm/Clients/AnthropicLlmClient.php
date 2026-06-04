<?php

namespace App\Services\Llm\Clients;

class AnthropicLlmClient extends FakeLlmClient
{
    public function __construct()
    {
        parent::__construct('anthropic');
    }
}
