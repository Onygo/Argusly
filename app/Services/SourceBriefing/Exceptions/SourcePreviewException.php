<?php

namespace App\Services\SourceBriefing\Exceptions;

use App\Models\ContentSource;
use RuntimeException;

class SourcePreviewException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ContentSource $source,
    ) {
        parent::__construct($message);
    }
}
