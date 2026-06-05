<?php

namespace App\Contracts;

interface PublicBlogSource
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchPublishedPosts(): array;
}

