<?php

use App\Enums\WordPressPostType;

describe('WordPressPostType enum', function () {
    describe('URL segments', function () {
        it('returns blog for POST type', function () {
            expect(WordPressPostType::POST->urlSegment())->toBe('blog');
        });

        it('returns knowledge-base for KNOWLEDGE_BASE type', function () {
            expect(WordPressPostType::KNOWLEDGE_BASE->urlSegment())->toBe('knowledge-base');
        });

        it('returns empty string for PAGE type', function () {
            expect(WordPressPostType::PAGE->urlSegment())->toBe('');
        });
    });

    describe('buildPlannedUrl', function () {
        it('builds correct URL for POST type', function () {
            $url = WordPressPostType::POST->buildPlannedUrl('https://example.com', 'my-article');
            expect($url)->toBe('https://example.com/blog/my-article');
        });

        it('builds correct URL for KNOWLEDGE_BASE type', function () {
            $url = WordPressPostType::KNOWLEDGE_BASE->buildPlannedUrl('https://example.com', 'my-guide');
            expect($url)->toBe('https://example.com/knowledge-base/my-guide');
        });

        it('builds correct URL for PAGE type without segment', function () {
            $url = WordPressPostType::PAGE->buildPlannedUrl('https://example.com', 'about-us');
            expect($url)->toBe('https://example.com/about-us');
        });

        it('handles empty base URL for POST', function () {
            $url = WordPressPostType::POST->buildPlannedUrl('', 'my-article');
            expect($url)->toBe('/blog/my-article');
        });

        it('handles empty base URL for KNOWLEDGE_BASE', function () {
            $url = WordPressPostType::KNOWLEDGE_BASE->buildPlannedUrl('', 'my-guide');
            expect($url)->toBe('/knowledge-base/my-guide');
        });

        it('handles empty base URL for PAGE', function () {
            $url = WordPressPostType::PAGE->buildPlannedUrl('', 'contact');
            expect($url)->toBe('/contact');
        });

        it('trims trailing slashes from base URL', function () {
            $url = WordPressPostType::POST->buildPlannedUrl('https://example.com/', 'my-article');
            expect($url)->toBe('https://example.com/blog/my-article');
        });
    });

    describe('fromContentType mapping', function () {
        it('maps knowledge_base to KNOWLEDGE_BASE', function () {
            expect(WordPressPostType::fromContentType('knowledge_base'))->toBe(WordPressPostType::KNOWLEDGE_BASE);
        });

        it('maps kb_article to KNOWLEDGE_BASE', function () {
            expect(WordPressPostType::fromContentType('kb_article'))->toBe(WordPressPostType::KNOWLEDGE_BASE);
        });

        it('maps article to POST', function () {
            expect(WordPressPostType::fromContentType('article'))->toBe(WordPressPostType::POST);
        });

        it('maps seo_page to PAGE', function () {
            expect(WordPressPostType::fromContentType('seo_page'))->toBe(WordPressPostType::PAGE);
        });

        it('maps landing to PAGE', function () {
            expect(WordPressPostType::fromContentType('landing'))->toBe(WordPressPostType::PAGE);
        });

        it('maps page to PAGE', function () {
            expect(WordPressPostType::fromContentType('page'))->toBe(WordPressPostType::PAGE);
        });

        it('defaults to POST for unknown types', function () {
            expect(WordPressPostType::fromContentType('unknown'))->toBe(WordPressPostType::POST);
        });

        it('defaults to POST for null', function () {
            expect(WordPressPostType::fromContentType(null))->toBe(WordPressPostType::POST);
        });

        it('handles case-insensitive input', function () {
            expect(WordPressPostType::fromContentType('KNOWLEDGE_BASE'))->toBe(WordPressPostType::KNOWLEDGE_BASE);
            expect(WordPressPostType::fromContentType('SEO_PAGE'))->toBe(WordPressPostType::PAGE);
        });
    });

    describe('fromOutputType mapping', function () {
        it('maps kb_article to KNOWLEDGE_BASE', function () {
            expect(WordPressPostType::fromOutputType('kb_article'))->toBe(WordPressPostType::KNOWLEDGE_BASE);
        });

        it('maps seo_page to PAGE', function () {
            expect(WordPressPostType::fromOutputType('seo_page'))->toBe(WordPressPostType::PAGE);
        });

        it('maps landing_page to PAGE', function () {
            expect(WordPressPostType::fromOutputType('landing_page'))->toBe(WordPressPostType::PAGE);
        });

        it('defaults to POST for unknown output types', function () {
            expect(WordPressPostType::fromOutputType('blog'))->toBe(WordPressPostType::POST);
            expect(WordPressPostType::fromOutputType('article'))->toBe(WordPressPostType::POST);
        });
    });

    describe('seriesOptions', function () {
        it('returns post and knowledge_base options', function () {
            $options = WordPressPostType::seriesOptions();

            expect($options)->toHaveKey('post');
            expect($options)->toHaveKey('knowledge_base');
            expect($options['post'])->toBe('Blog post');
            expect($options['knowledge_base'])->toBe('Knowledge base article');
        });
    });

    describe('wpRestEndpoint', function () {
        it('returns posts for POST type', function () {
            expect(WordPressPostType::POST->wpRestEndpoint())->toBe('posts');
        });

        it('returns knowledge_base for KNOWLEDGE_BASE type', function () {
            expect(WordPressPostType::KNOWLEDGE_BASE->wpRestEndpoint())->toBe('knowledge_base');
        });

        it('returns pages for PAGE type', function () {
            expect(WordPressPostType::PAGE->wpRestEndpoint())->toBe('pages');
        });
    });
});
