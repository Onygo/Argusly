<?php

use App\Enums\ContentType;

describe('ContentType enum', function () {
    it('has expected values', function () {
        expect(ContentType::values())->toBe([
            'article',
            'knowledge_base',
            'seo_page',
            'press_release',
        ]);
    });

    it('validates known values', function () {
        expect(ContentType::isValid('article'))->toBeTrue();
        expect(ContentType::isValid('knowledge_base'))->toBeTrue();
        expect(ContentType::isValid('seo_page'))->toBeTrue();
        expect(ContentType::isValid('press_release'))->toBeTrue();
    });

    it('rejects invalid values', function () {
        expect(ContentType::isValid('blog'))->toBeFalse();
        expect(ContentType::isValid('invalid'))->toBeFalse();
        expect(ContentType::isValid(''))->toBeFalse();
    });
});

describe('ContentType normalization', function () {
    it('normalizes blog to article', function () {
        expect(ContentType::normalize('blog'))->toBe(ContentType::ARTICLE);
        expect(ContentType::normalize('blog_post'))->toBe(ContentType::ARTICLE);
        expect(ContentType::normalize('post'))->toBe(ContentType::ARTICLE);
        expect(ContentType::normalize('BLOG'))->toBe(ContentType::ARTICLE);
        expect(ContentType::normalize(' Blog '))->toBe(ContentType::ARTICLE);
    });

    it('normalizes article variations', function () {
        expect(ContentType::normalize('article'))->toBe(ContentType::ARTICLE);
        expect(ContentType::normalize('kb_article'))->toBe(ContentType::ARTICLE);
    });

    it('normalizes knowledge_base variations', function () {
        expect(ContentType::normalize('knowledge_base'))->toBe(ContentType::KNOWLEDGE_BASE);
        expect(ContentType::normalize('kb'))->toBe(ContentType::KNOWLEDGE_BASE);
        expect(ContentType::normalize('help'))->toBe(ContentType::KNOWLEDGE_BASE);
        expect(ContentType::normalize('help_center'))->toBe(ContentType::KNOWLEDGE_BASE);
        expect(ContentType::normalize('docs'))->toBe(ContentType::KNOWLEDGE_BASE);
    });

    it('normalizes seo_page variations', function () {
        expect(ContentType::normalize('seo_page'))->toBe(ContentType::SEO_PAGE);
        expect(ContentType::normalize('landing'))->toBe(ContentType::SEO_PAGE);
        expect(ContentType::normalize('landing_page'))->toBe(ContentType::SEO_PAGE);
        expect(ContentType::normalize('page'))->toBe(ContentType::SEO_PAGE);
    });

    it('normalizes press_release variations', function () {
        expect(ContentType::normalize('press_release'))->toBe(ContentType::PRESS_RELEASE);
        expect(ContentType::normalize('press'))->toBe(ContentType::PRESS_RELEASE);
        expect(ContentType::normalize('pr'))->toBe(ContentType::PRESS_RELEASE);
    });

    it('defaults unknown values to article', function () {
        expect(ContentType::normalize('unknown'))->toBe(ContentType::ARTICLE);
        expect(ContentType::normalize(''))->toBe(ContentType::ARTICLE);
        expect(ContentType::normalize('random'))->toBe(ContentType::ARTICLE);
    });
});

describe('ContentType::fromString', function () {
    it('creates enum from string with normalization', function () {
        expect(ContentType::fromString('blog'))->toBe(ContentType::ARTICLE);
        expect(ContentType::fromString('landing'))->toBe(ContentType::SEO_PAGE);
        expect(ContentType::fromString('kb'))->toBe(ContentType::KNOWLEDGE_BASE);
    });
});

describe('ContentType::tryFromString', function () {
    it('returns enum for valid values', function () {
        expect(ContentType::tryFromString('article'))->toBe(ContentType::ARTICLE);
        expect(ContentType::tryFromString('knowledge_base'))->toBe(ContentType::KNOWLEDGE_BASE);
    });

    it('returns null for invalid values', function () {
        expect(ContentType::tryFromString('blog'))->toBeNull();
        expect(ContentType::tryFromString('invalid'))->toBeNull();
    });
});

describe('ContentType labels', function () {
    it('provides human-readable labels', function () {
        expect(ContentType::ARTICLE->label())->toBe('Article');
        expect(ContentType::KNOWLEDGE_BASE->label())->toBe('Knowledge Base');
        expect(ContentType::SEO_PAGE->label())->toBe('SEO Page');
        expect(ContentType::PRESS_RELEASE->label())->toBe('Press Release');
    });
});
