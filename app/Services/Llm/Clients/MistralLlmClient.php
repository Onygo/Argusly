<?php

namespace App\Services\Llm\Clients;

class MistralLlmClient extends FakeLlmClient
{
    public function __construct()
    {
        parent::__construct('mistral');
    }
}
