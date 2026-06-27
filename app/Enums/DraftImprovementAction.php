<?php

namespace App\Enums;

enum DraftImprovementAction: string
{
    case FULL_DRAFT = 'improve_full_draft';
    case HUMAN_CONTENT = 'human_content';
    case SEO = 'seo';
    case READABILITY = 'readability';
    case CTA = 'cta';
    case HEADINGS = 'headings';

    public function label(): string
    {
        return match ($this) {
            self::FULL_DRAFT => 'Improve entire draft',
            self::HUMAN_CONTENT => 'Improve Human Content score',
            self::SEO => 'Improve SEO',
            self::READABILITY => 'Improve readability',
            self::CTA => 'Add CTA',
            self::HEADINGS => 'Improve headings',
        };
    }

    public function summaryLabel(): string
    {
        return match ($this) {
            self::FULL_DRAFT => 'Full draft',
            self::HUMAN_CONTENT => 'Human Content',
            self::SEO => 'SEO',
            self::READABILITY => 'Readability',
            self::CTA => 'CTA',
            self::HEADINGS => 'Headings',
        };
    }

    public function queuedMessage(): string
    {
        return $this->label() . ' queued.';
    }

    public function successMessage(): string
    {
        return $this->label() . ' completed.';
    }

    public function failureMessage(): string
    {
        return $this->label() . ' failed.';
    }

    public function allowsSeoFieldUpdates(): bool
    {
        return in_array($this, [self::FULL_DRAFT, self::SEO], true);
    }

    public function description(): string
    {
        return match ($this) {
            self::FULL_DRAFT => 'Optimize SEO, readability, headings and CTA together.',
            self::HUMAN_CONTENT => 'Improve editorial quality, originality, evidence, rhythm, and human voice without changing facts or SEO intent.',
            self::SEO => 'Tighten keyword usage and SEO metadata without broad rewrites.',
            self::READABILITY => 'Improve flow, clarity, and sentence structure.',
            self::CTA => 'Add or strengthen the article’s next-step CTA.',
            self::HEADINGS => 'Refine heading clarity and structure.',
        };
    }

    public function isPrimary(): bool
    {
        return $this === self::FULL_DRAFT;
    }

    /**
     * @return array<int,string>
     */
    public function instructions(): array
    {
        return match ($this) {
            self::FULL_DRAFT => [
                'Improve the article holistically while preserving meaning, tone, and overall structure.',
                'Balance SEO, readability, headings, CTA, trust, brand voice, and publish readiness together so changes do not conflict.',
                'Refine or add a relevant CTA at the end and keep the article coherent from introduction to conclusion.',
            ],
            self::HUMAN_CONTENT => [
                'Improve the Human Content score by strengthening the central thesis, reader tension, expert judgment, evidence, specificity, rhythm, and practical implications.',
                'Rewrite generic headings, predictable openings/endings, filler, over-balanced phrasing, and summary-heavy passages only where needed.',
                'Preserve facts, entities, SEO intent, internal links, CTA intent, schema compatibility, brand voice, and the article meaning.',
                'Do not turn this into a generic SEO rewrite; keep the article editorial, specific, and useful.',
            ],
            self::SEO => [
                'Tighten on-page SEO without rewriting the whole article.',
                'You may improve title, seo_title, seo_meta_description, seo_h1, and supporting HTML copy.',
                'Preserve headings, links, and paragraph structure unless a small SEO fix requires a local change.',
            ],
            self::READABILITY => [
                'Improve clarity, sentence flow, and paragraph readability only.',
                'Do not change SEO metadata or overall article intent.',
                'Prefer local edits over large rewrites.',
            ],
            self::CTA => [
                'Add or improve one clear CTA in the article body.',
                'Use the brief CTA when available; otherwise infer one relevant CTA without inventing a product claim.',
                'Do not rewrite unrelated sections.',
            ],
            self::HEADINGS => [
                'Improve heading clarity and hierarchy only.',
                'Keep body copy as intact as possible outside heading-adjacent transitions.',
                'Do not change SEO metadata unless the existing H1 must be aligned with the heading structure.',
            ],
        };
    }

    public static function fromInput(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'improve_full_draft', 'full_draft', 'improve_full' => self::FULL_DRAFT,
            'human_content', 'improve_human_content', 'humanization_ai', 'editorial_quality' => self::HUMAN_CONTENT,
            'seo', 'improve_seo' => self::SEO,
            'readability', 'improve_readability' => self::READABILITY,
            'cta', 'add_cta' => self::CTA,
            'headings', 'heading', 'improve_headings', 'structure' => self::HEADINGS,
            default => null,
        };
    }

    /**
     * @return array<int,string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * @return array<int,array{key:string,label:string,description:string,primary:bool}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => [
                'key' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
                'primary' => $case->isPrimary(),
            ],
            self::cases(),
        );
    }
}
