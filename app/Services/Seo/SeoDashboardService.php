<?php

namespace App\Services\Seo;

use App\Models\Content;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SeoDashboardService
{
    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $missingMetadata = Content::query()
            ->where('type', 'article')
            ->where('status', 'published')
            ->where('publish_status', 'published')
            ->where(function ($query): void {
                $query->whereNull('seo_title')
                    ->orWhere('seo_title', '')
                    ->orWhereNull('seo_meta_description')
                    ->orWhere('seo_meta_description', '');
            })
            ->count();

        $missingAltText = Schema::hasColumn('content_images', 'alt_text')
            ? \App\Models\ContentImage::query()
                ->where('type', 'featured')
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('alt_text')->orWhere('alt_text', '');
                })
                ->count()
            : 0;

        return [
            'sitemap_url' => route('sitemaps.index'),
            'robots_url' => route('public.robots'),
            'canonical_audit_status' => Cache::get('seo.audit.canonicals.status', 'Not run yet'),
            'last_audit_run' => Cache::get('seo.audit.last_run'),
            'pages_missing_metadata' => $missingMetadata,
            'pages_missing_alt_text' => $missingAltText,
            'pages_with_no_internal_links' => Content::query()
                ->where('type', 'article')
                ->where('status', 'published')
                ->where('publish_status', 'published')
                ->where(function ($query): void {
                    $query->whereNull('internal_links_meta')->orWhere('internal_links_meta', '[]');
                })
                ->count(),
            'pages_excluded_from_index' => Content::query()
                ->where('type', 'article')
                ->where(function ($query): void {
                    $query->where('status', '<>', 'published')
                        ->orWhere('publish_status', '<>', 'published')
                        ->orWhere('robots_index', false);
                })
                ->count(),
            'recommendations' => [
                'Submit sitemap.xml in Google Search Console after deploy.',
                'Review pages missing metadata before requesting indexing.',
                'Add descriptive alt text before using generated featured images on public articles.',
            ],
        ];
    }
}
