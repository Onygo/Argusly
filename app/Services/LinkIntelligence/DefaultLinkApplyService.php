<?php

namespace App\Services\LinkIntelligence;

use App\Contracts\LinkIntelligence\LinkApplyService;
use App\DTO\LinkIntelligence\ApplyOptions;
use App\Models\CrossLinkPermission;
use App\Models\LinkSuggestion;
use Illuminate\Support\Facades\DB;

class DefaultLinkApplyService implements LinkApplyService
{
    public function applySuggestion(LinkSuggestion $suggestion, ApplyOptions $options): void
    {
        $suggestion->loadMissing('sourceArticle.clientSite.workspace', 'targetArticle.clientSite.workspace');

        if ($suggestion->status !== 'approved') {
            throw new \RuntimeException('Only approved suggestions can be applied.');
        }

        DB::transaction(function () use ($suggestion, $options): void {
            $source = $suggestion->sourceArticle;
            $target = $suggestion->targetArticle;

            $sourceHtml = (string) ($source->content_html ?? '');
            $url = $options->customUrl ?: $this->resolveTargetUrl($target);
            $rel = $this->resolveRelAttribute($suggestion);

            if ($options->placement === 'footnote') {
                $sourceHtml = $this->appendFootnote($sourceHtml, $target->title, $url, $rel);
            } else {
                $anchor = $this->resolveInlineAnchor($sourceHtml, $suggestion, $options);
                if (! $anchor) {
                    throw new \RuntimeException('Inline placement requires anchor text.');
                }

                $updatedHtml = $this->replaceFirstAnchor($sourceHtml, $anchor, $url, $rel);
                if ($updatedHtml === $sourceHtml) {
                    throw new \RuntimeException('Selected anchor text was not found in source content.');
                }

                $sourceHtml = $updatedHtml;
            }

            $source->update(['content_html' => $sourceHtml]);

            // Keep active content revision in sync, because delivery prefers revision content.
            $activeRevision = $source->content?->currentRevision;
            if ($activeRevision) {
                $activeRevision->update(['content_html' => $sourceHtml]);
            }

            $activeVersion = $source->content?->currentVersion;
            if ($activeVersion) {
                $activeVersion->update(['body' => $sourceHtml]);
            }

            $suggestion->update([
                'status' => 'applied',
                'applied_at' => now(),
            ]);
        });
    }

    private function resolveTargetUrl($target): string
    {
        $metaUrl = (string) data_get($target->meta, 'canonical_url', '');
        if ($metaUrl !== '') {
            return $metaUrl;
        }

        $siteUrl = rtrim((string) ($target->clientSite?->site_url ?? ''), '/');
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $target->title) ?? '', '-'));

        if ($siteUrl !== '' && $slug !== '') {
            return $siteUrl . '/' . $slug;
        }

        return $siteUrl !== '' ? $siteUrl : '#draft-' . $target->id;
    }

    private function resolveRelAttribute(LinkSuggestion $suggestion): string
    {
        if ($suggestion->source_workspace_id === $suggestion->target_workspace_id) {
            return 'follow';
        }

        $permission = CrossLinkPermission::query()
            ->where('from_workspace_id', $suggestion->source_workspace_id)
            ->where('to_workspace_id', $suggestion->target_workspace_id)
            ->where('status', 'approved')
            ->first();

        return (string) ($permission?->rel_attribute ?? 'follow');
    }

    private function appendFootnote(string $html, string $title, string $url, string $rel): string
    {
        $heading = (string) config('link_intelligence.limits.footnote_block_heading', 'Related reading');
        $relAttr = $rel === 'nofollow' ? ' rel="nofollow"' : '';

        $item = '<li><a href="' . e($url) . '"' . $relAttr . '>' . e($title) . '</a></li>';
        $block = '<section data-link-suggestions="1"><h3>' . e($heading) . '</h3><ul>' . $item . '</ul></section>';

        return $html . "\n" . $block;
    }

    private function replaceFirstAnchor(string $html, string $anchor, string $url, string $rel): string
    {
        $escapedAnchor = preg_quote($anchor, '/');
        $relAttr = $rel === 'nofollow' ? ' rel="nofollow"' : '';
        $replacement = '<a href="' . e($url) . '"' . $relAttr . '>' . e($anchor) . '</a>';

        $updated = preg_replace('/' . $escapedAnchor . '/i', $replacement, $html, 1);

        return $updated ?? $html;
    }

    private function resolveInlineAnchor(string $sourceHtml, LinkSuggestion $suggestion, ApplyOptions $options): string
    {
        $candidates = [];

        $explicitAnchor = trim((string) ($options->anchorText ?? ''));
        if ($explicitAnchor !== '') {
            $candidates[] = $explicitAnchor;
        }

        foreach ((array) ($suggestion->suggested_anchor_variants ?? []) as $variant) {
            $variant = trim((string) $variant);
            if ($variant !== '') {
                $candidates[] = $variant;
            }
        }

        foreach ((array) ($suggestion->shared_entities ?? []) as $entity) {
            $entity = trim((string) $entity);
            if ($entity !== '') {
                $candidates[] = $entity;
            }
        }

        $candidates = array_values(array_unique($candidates));

        foreach ($candidates as $candidate) {
            if (preg_match('/' . preg_quote($candidate, '/') . '/i', $sourceHtml) === 1) {
                return $candidate;
            }
        }

        return $candidates[0] ?? '';
    }
}
