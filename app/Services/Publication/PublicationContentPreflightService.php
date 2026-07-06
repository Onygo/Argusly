<?php

namespace App\Services\Publication;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use Illuminate\Support\Str;

class PublicationContentPreflightService
{
    /**
     * @return array{
     *   passed:bool,
     *   reasons:array<int,string>,
     *   message:string,
     *   duplicate_content_id:?string,
     *   duplicate_title:?string,
     *   duplicate_url:?string
     * }
     */
    public function evaluate(Content $content, ?Draft $draft = null): array
    {
        $titleKeys = $this->candidateTitleKeys($content, $draft);
        if ($titleKeys === []) {
            return $this->pass();
        }

        $duplicate = $this->findDuplicateTitle($content, $titleKeys);
        if (! $duplicate instanceof Content) {
            return $this->pass();
        }

        $duplicateTitle = trim((string) $duplicate->title);

        return [
            'passed' => false,
            'reasons' => ['duplicate_public_title'],
            'message' => $duplicateTitle !== ''
                ? sprintf('Publication blocked: an already published %s article has the same title "%s".', strtoupper($content->localeCode()), $duplicateTitle)
                : sprintf('Publication blocked: an already published %s article has the same title.', strtoupper($content->localeCode())),
            'duplicate_content_id' => (string) $duplicate->id,
            'duplicate_title' => $duplicateTitle,
            'duplicate_url' => trim((string) ($duplicate->published_url ?: $duplicate->seo_canonical ?: '')),
        ];
    }

    /**
     * @return array{
     *   passed:bool,
     *   reasons:array<int,string>,
     *   message:string,
     *   duplicate_content_id:null,
     *   duplicate_title:null,
     *   duplicate_url:null
     * }
     */
    private function pass(): array
    {
        return [
            'passed' => true,
            'reasons' => [],
            'message' => '',
            'duplicate_content_id' => null,
            'duplicate_title' => null,
            'duplicate_url' => null,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function candidateTitleKeys(Content $content, ?Draft $draft): array
    {
        return collect([
            $content->title,
            $draft?->title,
            $content->seo_title,
            $draft?->seo_title,
        ])
            ->map(fn (mixed $title): string => $this->titleKey((string) $title))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $titleKeys
     */
    private function findDuplicateTitle(Content $content, array $titleKeys): ?Content
    {
        $content->loadMissing('clientSite');
        $locale = $content->localeCode();

        return Content::query()
            ->select(['id', 'workspace_id', 'client_site_id', 'title', 'language', 'published_url', 'seo_canonical', 'updated_at'])
            ->where('id', '!=', (string) $content->id)
            ->where('type', 'article')
            ->where('language', $locale)
            ->when(
                trim((string) $content->client_site_id) !== '',
                fn ($query) => $query->where('client_site_id', (string) $content->client_site_id),
                fn ($query) => $query->where('workspace_id', (string) $content->workspace_id),
            )
            ->where(function ($query) use ($locale): void {
                $query->where(function ($publishedQuery): void {
                    $publishedQuery
                        ->where('status', 'published')
                        ->where('publish_status', 'published');
                })->orWhereHas('publications', function ($publicationQuery) use ($locale): void {
                    $publicationQuery
                        ->where('delivery_status', ContentPublication::STATUS_DELIVERED)
                        ->where(function ($statusQuery): void {
                            $statusQuery
                                ->whereIn('remote_status', [ContentPublication::REMOTE_PUBLISHED, 'publish', 'live'])
                                ->orWhereNull('remote_status')
                                ->orWhere('remote_status', '');
                        })
                        ->where(function ($localeQuery) use ($locale): void {
                            $localeQuery
                                ->where('locale', $locale)
                                ->orWhereNull('locale');
                        });
                });
            })
            ->latest('updated_at')
            ->get()
            ->first(fn (Content $candidate): bool => in_array($this->titleKey((string) $candidate->title), $titleKeys, true));
    }

    private function titleKey(string $title): string
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return Str::lower(trim($title));
    }
}
