<?php

namespace App\Services\Llm\Clients;

class GoogleLlmClient extends FakeLlmClient
{
    public function __construct()
    {
        parent::__construct('google');
    }
}
