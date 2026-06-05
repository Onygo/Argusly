<?php

namespace App\Services\PublicBlog;

class MarketingBlogSourceScope
{
    /**
     * @return array{mode:string,id:string}|null
     */
    public function resolve(): ?array
    {
        $mode = strtolower(trim((string) config('marketing.blog_source.mode', 'workspace')));
        $id = trim((string) config('marketing.blog_source.id', ''));

        if ($id === '') {
            return null;
        }

        if (! in_array($mode, ['workspace', 'site'], true)) {
            return null;
        }

        return [
            'mode' => $mode,
            'id' => $id,
        ];
    }

    public function isConfigured(): bool
    {
        return $this->resolve() !== null;
    }

    public function localColumnForMode(string $mode): ?string
    {
        return match ($mode) {
            'workspace' => 'workspace_id',
            'site' => 'client_site_id',
            default => null,
        };
    }

    public function queryParamForMode(string $mode): ?string
    {
        return match ($mode) {
            'workspace' => 'workspace_id',
            'site' => 'client_site_id',
            default => null,
        };
    }
}
