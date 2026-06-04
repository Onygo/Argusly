<?php

namespace App\Services\Llm\Clients;

class GroqLlmClient extends FakeLlmClient
{
    public function __construct()
    {
        parent::__construct('groq');
    }
}
