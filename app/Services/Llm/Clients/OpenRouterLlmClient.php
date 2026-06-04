<?php

namespace App\Services\Llm\Clients;

class OpenRouterLlmClient extends FakeLlmClient
{
    public function __construct()
    {
        parent::__construct('openrouter');
    }
}
