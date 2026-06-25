<?php

namespace App\Services\EmailMarketing;

use RuntimeException;

class EmailMarketingProviderException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
