<?php

use App\Support\DescriptionSanitizer;

describe('DescriptionSanitizer', function () {
    describe('normalizeMetaDescription', function () {
        it('keeps valid short descriptions unchanged', function () {
            $desc = 'Learn how to improve your content marketing strategy with proven techniques.';

            expect(DescriptionSanitizer::normalizeMetaDescription($desc))->toBe($desc);
        });

        it('truncates descriptions exceeding 160 characters', function () {
            $longDesc = str_repeat('This is a test sentence. ', 20);

            $result = DescriptionSanitizer::normalizeMetaDescription($longDesc);

            expect(mb_strlen($result))->toBeLessThanOrEqual(DescriptionSanitizer::META_DESCRIPTION_MAX);
        });

        it('truncates at sentence boundary when possible', function () {
            // First sentence is 61 chars (>50% of 100), total is >100, so sentence boundary should be used
            $desc = 'This is a complete and sufficiently long first sentence here. Second sentence adds much more extra content to exceed the limit.';

            $result = DescriptionSanitizer::normalizeMetaDescription($desc, maxLength: 100);

            // Should end at first sentence boundary (61 chars including period)
            expect($result)->toBe('This is a complete and sufficiently long first sentence here.');
            expect(mb_strlen($result))->toBeLessThanOrEqual(100);
        });

        it('returns fallback for empty descriptions', function () {
            expect(DescriptionSanitizer::normalizeMetaDescription('', 'Default description'))->toBe('Default description');
            expect(DescriptionSanitizer::normalizeMetaDescription('   ', 'Fallback'))->toBe('Fallback');
        });

        it('strips HTML tags from descriptions', function () {
            $html = '<p>This is a <strong>description</strong> with HTML.</p>';

            expect(DescriptionSanitizer::normalizeMetaDescription($html))->toBe('This is a description with HTML.');
        });

        it('decodes HTML entities', function () {
            $desc = 'Learn about B&amp;B marketing &amp; growth strategies.';

            expect(DescriptionSanitizer::normalizeMetaDescription($desc))->toBe('Learn about B&B marketing & growth strategies.');
        });
    });

    describe('normalizeOgDescription', function () {
        it('uses OG-specific max length of 300', function () {
            $longDesc = str_repeat('Word ', 100);

            $result = DescriptionSanitizer::normalizeOgDescription($longDesc);

            expect(mb_strlen($result))->toBeLessThanOrEqual(DescriptionSanitizer::OG_DESCRIPTION_MAX);
        });
    });

    describe('normalizeTwitterDescription', function () {
        it('uses Twitter-specific max length of 200', function () {
            $longDesc = str_repeat('Word ', 80);

            $result = DescriptionSanitizer::normalizeTwitterDescription($longDesc);

            expect(mb_strlen($result))->toBeLessThanOrEqual(DescriptionSanitizer::TWITTER_DESCRIPTION_MAX);
        });
    });

    describe('normalizeCanonicalUrl', function () {
        it('returns null for null input', function () {
            expect(DescriptionSanitizer::normalizeCanonicalUrl(null))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect(DescriptionSanitizer::normalizeCanonicalUrl(''))->toBeNull();
        });

        it('keeps valid URLs unchanged', function () {
            $url = 'https://example.com/blog/article-slug';

            expect(DescriptionSanitizer::normalizeCanonicalUrl($url))->toBe($url);
        });

        it('adds https prefix if missing', function () {
            expect(DescriptionSanitizer::normalizeCanonicalUrl('example.com/page'))->toBe('https://example.com/page');
        });

        it('truncates overly long URLs', function () {
            $longUrl = 'https://example.com/' . str_repeat('a', 3000);

            $result = DescriptionSanitizer::normalizeCanonicalUrl($longUrl);

            expect(mb_strlen($result))->toBeLessThanOrEqual(DescriptionSanitizer::CANONICAL_URL_MAX);
        });
    });

    describe('rejects invalid content', function () {
        it('rejects JSON fragments', function () {
            $json = '{"seo_meta_description": "This is the actual description"}';

            $result = DescriptionSanitizer::normalizeWithMetadata($json);

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('json_fragment');
        });

        it('rejects prompt-like text', function () {
            $prompt = 'You are an SEO expert. Write a meta description for this article about marketing.';

            $result = DescriptionSanitizer::normalizeWithMetadata($prompt);

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('prompt_text');
        });

        it('rejects markdown code blocks', function () {
            // Use markdown without JSON-like content to avoid JSON detection triggering first
            $markdown = 'Here is info: ```python\nprint("hello")\n```';

            $result = DescriptionSanitizer::normalizeWithMetadata($markdown);

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('markdown_code_block');
        });

        it('strips HTML structure tags and keeps content', function () {
            // HTML tags are stripped by toCleanString, so we get clean text
            $html = '<div class="seo-meta"><p>Description here</p></div>';

            $result = DescriptionSanitizer::normalizeWithMetadata($html);

            // The content is extracted and valid
            expect($result['description'])->toBe('Description here');
            expect($result['was_rejected'])->toBeFalse();
        });

        it('rejects URL-heavy content', function () {
            $urls = 'Check out https://example.com and https://test.com and also https://another.com for more info.';

            $result = DescriptionSanitizer::normalizeWithMetadata($urls);

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('url_heavy_content');
        });
    });

    describe('normalizeWithMetadata', function () {
        it('returns full metadata for valid description', function () {
            $desc = 'A valid SEO meta description for testing.';

            $result = DescriptionSanitizer::normalizeWithMetadata($desc);

            expect($result)->toHaveKeys([
                'description',
                'original_value',
                'was_sanitized',
                'was_truncated',
                'was_rejected',
                'rejection_reason',
                'original_length',
                'persisted_length',
                'max_length',
            ]);

            expect($result['description'])->toBe($desc);
            expect($result['was_sanitized'])->toBeFalse();
            expect($result['was_rejected'])->toBeFalse();
        });

        it('indicates truncation when description was shortened', function () {
            $longDesc = str_repeat('This is a test. ', 30);

            $result = DescriptionSanitizer::normalizeWithMetadata($longDesc);

            expect($result['was_sanitized'])->toBeTrue();
            expect($result['was_truncated'])->toBeTrue();
            expect($result['persisted_length'])->toBeLessThan($result['original_length']);
        });
    });

    describe('handles edge cases', function () {
        it('handles array input gracefully', function () {
            $result = DescriptionSanitizer::normalizeWithMetadata(['description' => 'test']);

            expect($result['description'])->toBe('');
            expect($result['original_value'])->toBe('');
        });

        it('normalizes excessive whitespace', function () {
            $desc = "This   has   extra    spaces\n\nand\n\nnewlines.";

            $result = DescriptionSanitizer::normalizeMetaDescription($desc);

            expect($result)->toBe('This has extra spaces and newlines.');
        });

        it('uses default max length constant', function () {
            expect(DescriptionSanitizer::META_DESCRIPTION_MAX)->toBe(160);
            expect(DescriptionSanitizer::OG_DESCRIPTION_MAX)->toBe(300);
            expect(DescriptionSanitizer::TWITTER_DESCRIPTION_MAX)->toBe(200);
            expect(DescriptionSanitizer::CANONICAL_URL_MAX)->toBe(2048);
        });
    });
});
