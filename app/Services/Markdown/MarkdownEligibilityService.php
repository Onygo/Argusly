<?php

namespace App\Services\Markdown;

use App\Enums\ContentSource;
use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\ContentPublication;

class MarkdownEligibilityService
{
    /**
     * @return array{eligible:bool,reason:string,locale:string}
     */
    public function evaluate(Content $content, string|SupportedLanguage|null $locale = null): array
    {
        $resolvedLocale = $this->resolveLocale($content, $locale);
        $status = strtolower(trim((string) $content->status));
        $publishStatus = strtolower(trim((string) ($content->publish_status ?? 'draft')));
        $type = strtolower(trim((string) $content->type));
        $rawSource = $content->getRawOriginal('source');
        $source = $rawSource instanceof ContentSource
            ? $rawSource->value
            : ContentSource::normalize((string) $rawSource)->value;

        if (in_array($status, ['brief_received', 'brief', 'draft', 'review', 'archived'], true)) {
            return $this->decision(false, 'status_not_public', $resolvedLocale);
        }

        if (in_array($publishStatus, ['draft', 'private', 'internal', 'system'], true)) {
            return $this->decision(false, 'publish_status_not_public', $resolvedLocale);
        }

        if (in_array($type, ['private', 'internal', 'system'], true)) {
            return $this->decision(false, 'content_type_not_public', $resolvedLocale);
        }

        if (in_array($source, ['internal', 'system'], true)) {
            return $this->decision(false, 'content_source_not_public', $resolvedLocale);
        }

        if ($this->hasPrivatePublication($content)) {
            return $this->decision(false, 'remote_private', $resolvedLocale);
        }

        if (
            in_array($status, ['approved', 'ready_to_deliver', 'scheduled', 'published', 'delivered'], true)
            || in_array($publishStatus, ['scheduled', 'publishing', 'published'], true)
        ) {
            return $this->decision(true, 'eligible', $resolvedLocale);
        }

        return $this->decision(false, 'not_publishable_yet', $resolvedLocale);
    }

    public function isEligible(Content $content, string|SupportedLanguage|null $locale = null): bool
    {
        return $this->evaluate($content, $locale)['eligible'];
    }

    public function resolveLocale(Content $content, string|SupportedLanguage|null $locale = null): string
    {
        if ($locale instanceof SupportedLanguage) {
            return $locale->value;
        }

        $explicit = strtolower(trim((string) $locale));
        if ($explicit !== '') {
            return SupportedLanguage::fromStringOrDefault($explicit)->value;
        }

        if ($content->language instanceof SupportedLanguage) {
            return $content->language->value;
        }

        if ($content->relationLoaded('workspace') && $content->workspace) {
            return $content->workspace->default_content_language->value;
        }

        if ($content->workspace()->exists()) {
            return $content->workspace()->value('default_content_language') ?: SupportedLanguage::default()->value;
        }

        return SupportedLanguage::default()->value;
    }

    private function hasPrivatePublication(Content $content): bool
    {
        if ($content->relationLoaded('publications')) {
            return $content->publications->contains(
                fn (ContentPublication $publication) => strtolower((string) $publication->remote_status) === 'private'
            );
        }

        return $content->publications()
            ->where('remote_status', 'private')
            ->exists();
    }

    /**
     * @return array{eligible:bool,reason:string,locale:string}
     */
    private function decision(bool $eligible, string $reason, string $locale): array
    {
        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'locale' => $locale,
        ];
    }
}
