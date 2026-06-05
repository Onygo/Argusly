<?php

namespace App\Services\QueryIntent;

use Illuminate\Support\Str;

class QueryIntentTextNormalizer
{
    public function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';

        return Str::limit(trim($text), 12000, '');
    }
}
