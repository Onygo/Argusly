<?php

namespace App\Services\Markdown;

use App\Models\Content;
use App\Models\ContentRenderArtifact;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarkdownArtifactService
{
    public function __construct(
        private readonly MarkdownEligibilityService $eligibility,
        private readonly MarkdownChecksumService $checksums,
        private readonly MarkdownRenderer $renderer
    ) {}

    public function findForContent(Content $content, string|null $locale = null): ?ContentRenderArtifact
    {
        $resolvedLocale = $this->eligibility->resolveLocale($content, $locale);

        if ($content->relationLoaded('renderArtifacts')) {
            return $content->renderArtifacts
                ->first(fn (ContentRenderArtifact $artifact) => $artifact->markdown_locale?->value === $resolvedLocale);
        }

        return $content->renderArtifacts()
            ->forLocale($resolvedLocale)
            ->first();
    }

    public function rebuildForContentId(string $contentId, string|null $locale = null, bool $force = false): ?ContentRenderArtifact
    {
        $content = Content::query()
            ->with(['workspace', 'currentRevision', 'currentVersion', 'publications', 'renderArtifacts', 'seo', 'teamMember'])
            ->find($contentId);

        if (! $content) {
            return null;
        }

        return $this->rebuildForContent($content, $locale, $force);
    }

    public function rebuildForContent(Content $content, string|null $locale = null, bool $force = false): ContentRenderArtifact
    {
        $content->loadMissing(['workspace', 'currentRevision', 'currentVersion', 'publications', 'renderArtifacts', 'seo', 'teamMember']);

        $decision = $this->eligibility->evaluate($content, $locale);

        if (! $decision['eligible']) {
            return $this->storeArtifact($content, [
                'markdown_locale' => $decision['locale'],
                'markdown_status' => ContentRenderArtifact::STATUS_INELIGIBLE,
                'markdown_source' => $decision['reason'],
                'content_version_id' => null,
                'rendered_html' => null,
                'rendered_markdown' => null,
                'markdown_checksum' => null,
                'markdown_generated_at' => null,
                'markdown_excerpt' => null,
            ]);
        }

        $artifact = $this->findForContent($content, $decision['locale']);
        $currentVersionId = $content->current_version_id;

        if (
            ! $force
            && $artifact
            && $artifact->markdown_status === ContentRenderArtifact::STATUS_READY
            && (string) ($artifact->content_version_id ?? '') === (string) ($currentVersionId ?? '')
            && trim((string) $artifact->rendered_markdown) !== ''
        ) {
            return $artifact;
        }

        $rendered = $this->renderer->render($content, $decision['locale']);

        return $this->storeArtifact($content, [
            'markdown_locale' => $decision['locale'],
            'content_version_id' => $currentVersionId,
            'rendered_html' => $rendered['rendered_html'],
            'rendered_markdown' => $rendered['rendered_markdown'],
            'markdown_status' => trim((string) $rendered['rendered_markdown']) !== ''
                ? ContentRenderArtifact::STATUS_READY
                : ContentRenderArtifact::STATUS_PENDING,
            'markdown_source' => $rendered['source'],
            'markdown_generated_at' => now(),
            'markdown_excerpt' => $this->makeExcerpt($rendered['excerpt'], $rendered['rendered_markdown'], $rendered['rendered_html']),
            'meta' => $rendered['meta'],
        ]);
    }

    public function markStaleForContent(Content $content, string|null $locale = null): ?ContentRenderArtifact
    {
        $content->loadMissing(['workspace', 'renderArtifacts']);

        $decision = $this->eligibility->evaluate($content, $locale);
        if (! $decision['eligible']) {
            return null;
        }

        $artifact = $this->findForContent($content, $decision['locale']);
        if (! $artifact || $artifact->markdown_status !== ContentRenderArtifact::STATUS_READY) {
            return $artifact;
        }

        $artifact->forceFill([
            'markdown_status' => ContentRenderArtifact::STATUS_STALE,
        ])->save();

        return $artifact->fresh();
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function storeArtifact(Content $content, array $attributes): ContentRenderArtifact
    {
        $resolvedLocale = $this->eligibility->resolveLocale($content, Arr::get($attributes, 'markdown_locale'));

        return DB::transaction(function () use ($content, $attributes, $resolvedLocale): ContentRenderArtifact {
            /** @var ContentRenderArtifact $artifact */
            $artifact = ContentRenderArtifact::query()->firstOrNew([
                'content_id' => $content->id,
                'markdown_locale' => $resolvedLocale,
            ]);

            $existingVersion = max(1, (int) ($artifact->markdown_version ?: 1));
            $targetVersion = array_key_exists('markdown_version', $attributes)
                ? max(1, (int) $attributes['markdown_version'])
                : $existingVersion;

            foreach ([
                'content_version_id',
                'rendered_html',
                'rendered_markdown',
                'markdown_generated_at',
                'markdown_status',
                'markdown_source',
                'markdown_excerpt',
                'meta',
            ] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $artifact->{$key} = $attributes[$key];
                }
            }

            $artifact->content_id = $content->id;
            $artifact->markdown_locale = $resolvedLocale;

            if (
                blank($artifact->markdown_excerpt)
                && (trim((string) $artifact->rendered_markdown) !== '' || trim((string) $artifact->rendered_html) !== '')
            ) {
                $artifact->markdown_excerpt = $this->makeExcerpt(null, $artifact->rendered_markdown, $artifact->rendered_html);
            }

            $checksum = array_key_exists('markdown_checksum', $attributes)
                ? $attributes['markdown_checksum']
                : $this->checksums->generate(
                    $artifact->rendered_markdown,
                    $artifact->rendered_html,
                    $resolvedLocale,
                    $targetVersion
                );

            if ($artifact->exists && $checksum !== null && $checksum !== $artifact->markdown_checksum && ! array_key_exists('markdown_version', $attributes)) {
                $targetVersion = $existingVersion + 1;
                $checksum = $this->checksums->generate(
                    $artifact->rendered_markdown,
                    $artifact->rendered_html,
                    $resolvedLocale,
                    $targetVersion
                );
            }

            $artifact->markdown_version = $targetVersion;
            $artifact->markdown_checksum = $checksum;

            if (
                blank($artifact->markdown_status)
                && trim((string) $artifact->rendered_markdown) !== ''
            ) {
                $artifact->markdown_status = ContentRenderArtifact::STATUS_READY;
            } elseif (blank($artifact->markdown_status)) {
                $artifact->markdown_status = ContentRenderArtifact::STATUS_PENDING;
            }

            $artifact->save();

            return $artifact->fresh();
        });
    }

    private function makeExcerpt(?string $excerpt, ?string $markdown, ?string $html): ?string
    {
        $source = trim((string) ($excerpt ?: $markdown ?: strip_tags((string) $html)));
        $source = preg_replace('/\s+/u', ' ', $source) ?? '';
        $source = trim($source);

        return $source === '' ? null : Str::limit($source, 280);
    }
}
