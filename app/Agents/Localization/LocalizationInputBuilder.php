<?php

namespace App\Agents\Localization;

use App\Agents\Data\AgentContext;
use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\Draft;
use App\Services\Content\ContentHealthService;
use App\Services\Content\ContentTranslationCoordinator;
use App\Services\Content\LocaleContentMapService;
use App\Services\Translation\TranslationService;
use Illuminate\Support\Collection;

class LocalizationInputBuilder
{
    public function __construct(
        private readonly ContentHealthService $contentHealthService,
        private readonly LocaleContentMapService $localeContentMapService,
        private readonly ContentTranslationCoordinator $contentTranslationCoordinator,
        private readonly TranslationService $translationService,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function build(AgentContext $context): array
    {
        if ($context->draftId !== null) {
            return $this->buildForDraftContext($context);
        }

        return $this->buildForContentContext($context);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildForDraftContext(AgentContext $context): array
    {
        $draft = Draft::query()
            ->with([
                'brief',
                'clientSite.workspace',
                'content.translationSourceContent',
                'sourceDraft.brief',
                'sourceDraft.content.translationSourceContent',
                'sourceDraft.content.localizedVariants',
                'translations',
            ])
            ->findOrFail($context->draftId);

        $lineageRoot = $draft->getOriginalSourceDraft() ?? $draft;
        $lineageRoot->loadMissing('translations.content', 'translations.brief');

        $content = $draft->content;
        $health = $this->contentHealthService->snapshot($content, (string) ($draft->content_html ?? ''), (string) ($draft->clientSite?->site_url ?: $draft->clientSite?->base_url ?: ''));
        $translationTargets = collect($this->translationService->canTranslateToLanguages($lineageRoot))
            ->map(fn (SupportedLanguage $language): array => [
                'locale' => $language->value,
                'label' => $language->englishLabel(),
                'native_label' => $language->label(),
            ])
            ->values()
            ->all();

        return [
            'resource_type' => 'draft',
            'draft' => $draft,
            'site' => $draft->clientSite,
            'linked_content' => $content,
            'declared_locale' => SupportedLanguage::fromStringOrDefault((string) $draft->getRawOriginal('language'))->value,
            'title' => trim((string) $draft->title),
            'plain_text' => (string) ($health['plain_text'] ?? ''),
            'body_html' => (string) ($health['html'] ?? ''),
            'lineage_root_draft' => $lineageRoot,
            'source_draft' => $draft->sourceDraft,
            'source_draft_locale' => $draft->sourceDraft
                ? SupportedLanguage::fromStringOrDefault((string) $draft->sourceDraft->getRawOriginal('language'))->value
                : null,
            'source_draft_updated_at' => $draft->sourceDraft?->updated_at,
            'linked_content_locale' => $content?->localeCode(),
            'translation_targets' => $translationTargets,
            'missing_fields' => $this->missingDraftFields($draft),
            'source_missing_fields' => $draft->sourceDraft ? $this->missingDraftFields($draft->sourceDraft) : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildForContentContext(AgentContext $context): array
    {
        $content = Content::query()
            ->with([
                'workspace',
                'clientSite.workspace',
                'brief',
                'drafts' => fn ($query) => $query->latest('created_at')->limit(5),
                'translationSourceContent',
                'localizedVariants.brief',
                'localizedVariants.currentRevision',
                'localizedVariants.currentVersion',
                'localizedVariants.clientSite',
                'currentRevision',
                'currentVersion',
            ])
            ->findOrFail($context->contentId);

        $source = $this->localeContentMapService->source($content);
        $family = $this->localeContentMapService->family($content);
        $health = $this->contentHealthService->snapshot($content);
        $translationTargets = $this->contentTranslationCoordinator->targetLocales($content)
            ->map(fn (array $target): array => [
                'locale' => (string) ($target['value'] ?? ''),
                'label' => (string) ($target['label'] ?? ''),
                'native_label' => (string) ($target['native_label'] ?? ''),
                'action' => (string) ($target['action'] ?? 'translate'),
                'existing_variant_id' => data_get($target, 'existing_variant.id'),
            ])
            ->values()
            ->all();

        return [
            'resource_type' => 'content',
            'content' => $content,
            'site' => $content->clientSite,
            'source_content' => $source,
            'declared_locale' => $content->localeCode(),
            'title' => trim((string) $content->title),
            'plain_text' => (string) ($health['plain_text'] ?? ''),
            'body_html' => (string) ($health['html'] ?? ''),
            'translation_targets' => $translationTargets,
            'family_matrix' => $family
                ->map(fn (Content $variant): array => $this->describeContentVariant($variant, $source, $content))
                ->values()
                ->all(),
            'source_missing_fields' => $this->missingContentFields($source),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function describeContentVariant(Content $variant, Content $source, Content $current): array
    {
        return [
            'content' => $variant,
            'locale' => $variant->localeCode(),
            'is_source' => (string) $variant->id === (string) $source->id || ! $variant->isTranslationVariant() || (bool) $variant->is_source_locale,
            'is_current' => (string) $variant->id === (string) $current->id,
            'is_outdated' => $variant->isTranslationOutdated(),
            'missing_fields' => $this->missingContentFields($variant),
            'slug_missing' => trim((string) ($variant->publish_url_key ?? '')) === '',
            'translation_source_locale' => SupportedLanguage::normalizeLocale((string) ($variant->translation_source_locale ?? '')),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function missingDraftFields(Draft $draft): array
    {
        return collect([
            'seo_title' => trim((string) ($draft->seo_title ?? '')),
            'seo_meta_description' => trim((string) ($draft->seo_meta_description ?? '')),
            'seo_h1' => trim((string) ($draft->seo_h1 ?? '')),
        ])->filter(fn (string $value): bool => $value === '')
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function missingContentFields(Content $content): array
    {
        return collect([
            'seo_title' => trim((string) ($content->seo_title ?? '')),
            'seo_meta_description' => trim((string) ($content->seo_meta_description ?? '')),
            'seo_h1' => trim((string) ($content->seo_h1 ?? '')),
            'publish_url_key' => trim((string) ($content->publish_url_key ?? '')),
        ])->filter(fn (string $value): bool => $value === '')
            ->keys()
            ->values()
            ->all();
    }
}
