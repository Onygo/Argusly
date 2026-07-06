<?php

namespace App\Contracts;

interface ConnectorPublicBlogSource extends PublicBlogSource
{
    public function isEnabled(): bool;
}
