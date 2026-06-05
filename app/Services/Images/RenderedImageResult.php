<?php

namespace App\Services\Images;

class RenderedImageResult
{
    public function __construct(
        public readonly string $path,
        public readonly string $url
    ) {}
}
