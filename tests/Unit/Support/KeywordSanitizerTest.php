<?php

use App\Support\KeywordSanitizer;

describe('KeywordSanitizer', function () {
    describe('normalize', function () {
        it('keeps valid short keywords unchanged', function () {
            expect(KeywordSanitizer::normalize('content marketing'))->toBe('content marketing');
            expect(KeywordSanitizer::normalize('SEO strategy'))->toBe('SEO strategy');
            expect(KeywordSanitizer::normalize('b2b automation'))->toBe('b2b automation');
        });

        it('trims whitespace from keywords', function () {
            expect(KeywordSanitizer::normalize('  content marketing  '))->toBe('content marketing');
            expect(KeywordSanitizer::normalize("\t\nkeyword\t\n"))->toBe('keyword');
        });

        it('strips HTML tags from keywords', function () {
            expect(KeywordSanitizer::normalize('<strong>seo</strong> strategy'))->toBe('seo strategy');
            expect(KeywordSanitizer::normalize('<p>marketing</p>'))->toBe('marketing');
        });

        it('decodes HTML entities', function () {
            expect(KeywordSanitizer::normalize('B&amp;B marketing'))->toBe('B&B marketing');
            expect(KeywordSanitizer::normalize('&quot;content&quot;'))->toBe('"content"');
        });

        it('returns fallback for empty keywords', function () {
            expect(KeywordSanitizer::normalize('', fallback: 'default keyword'))->toBe('default keyword');
            expect(KeywordSanitizer::normalize('   ', fallback: 'fallback'))->toBe('fallback');
            expect(KeywordSanitizer::normalize('', fallback: null))->toBe('');
        });

        it('truncates keywords exceeding max length', function () {
            $longKeyword = str_repeat('keyword ', 50);

            $result = KeywordSanitizer::normalize($longKeyword);

            expect(mb_strlen($result))->toBeLessThanOrEqual(KeywordSanitizer::MAX_LENGTH);
        });

        it('truncates at word boundary when possible', function () {
            // Create a string just over the limit with clear word boundaries
            $keyword = 'short keyword followed by more words and ' . str_repeat('longword ', 30);

            $result = KeywordSanitizer::normalize($keyword);

            expect(mb_strlen($result))->toBeLessThanOrEqual(KeywordSanitizer::MAX_LENGTH);
            // Should not end with a partial word fragment
            expect($result)->not->toEndWith('longwor');
        });
    });

    describe('rejects invalid content', function () {
        it('rejects JSON fragments', function () {
            $result = KeywordSanitizer::normalizeWithMetadata('{"key": "value", "primary_keyword": "test"}');

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('json_fragment');
        });

        it('rejects array/object inputs', function () {
            $result = KeywordSanitizer::normalizeWithMetadata(['keyword1', 'keyword2']);

            expect($result['keyword'])->toBe('');
            expect($result['original_value'])->toBe('');
        });

        it('rejects paragraph-like content with multiple sentences', function () {
            $paragraph = 'This is the first sentence. Here is another sentence following it.';

            $result = KeywordSanitizer::normalizeWithMetadata($paragraph);

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('multiple_sentences');
        });

        it('rejects content containing newlines', function () {
            $result = KeywordSanitizer::normalizeWithMetadata("keyword one\nkeyword two");

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('contains_newlines');
        });

        it('rejects content with too many words', function () {
            $tooManyWords = 'this is a very long phrase with way too many words for a keyword';

            $result = KeywordSanitizer::normalizeWithMetadata($tooManyWords);

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('too_many_words');
        });

        it('rejects prompt-like text', function () {
            $testCases = [
                'You are an SEO expert writing about keywords',
                'Write a content marketing strategy',
                'Generate a list of keywords',
                'Please write me a keyphrase',
            ];

            foreach ($testCases as $prompt) {
                $result = KeywordSanitizer::normalizeWithMetadata($prompt);

                expect($result['was_rejected'])->toBeTrue("Expected '$prompt' to be rejected");
                expect($result['rejection_reason'])->toBe('prompt_text');
            }
        });

        it('rejects metadata fragments with special characters', function () {
            $metadata = str_repeat('x', 70) . ' {nested: [data]}';

            $result = KeywordSanitizer::normalizeWithMetadata($metadata);

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->toBe('metadata_fragment');
        });
    });

    describe('derives keywords from problematic text', function () {
        it('extracts usable keyphrase from long sentence', function () {
            $sentence = 'content marketing is very important for business growth and success';

            $result = KeywordSanitizer::normalizeWithMetadata($sentence, fallback: 'fallback');

            expect($result['was_rejected'])->toBeTrue();
            // Should derive something or use fallback
            expect($result['keyword'])->not->toBe('');
        });

        it('uses fallback when derivation fails completely', function () {
            $garbage = '{"title": "full json object", "prompt": "system prompt here"}';

            $result = KeywordSanitizer::normalizeWithMetadata($garbage, fallback: 'safe fallback');

            expect($result['was_rejected'])->toBeTrue();
            // Either derived or fallback should be used
            expect($result['keyword'])->not->toBe('');
        });
    });

    describe('normalizeWithMetadata', function () {
        it('returns full metadata for valid keyword', function () {
            $result = KeywordSanitizer::normalizeWithMetadata('seo strategy');

            expect($result)->toHaveKeys([
                'keyword',
                'original_value',
                'was_sanitized',
                'was_truncated',
                'was_rejected',
                'rejection_reason',
                'original_length',
                'persisted_length',
                'max_length',
            ]);

            expect($result['keyword'])->toBe('seo strategy');
            expect($result['was_sanitized'])->toBeFalse();
            expect($result['was_truncated'])->toBeFalse();
            expect($result['was_rejected'])->toBeFalse();
            expect($result['rejection_reason'])->toBeNull();
        });

        it('indicates truncation when keyword was shortened', function () {
            // Create a long string that stays under MAX_WORD_COUNT but exceeds MAX_LENGTH
            // Using 5 words with each word being ~60 chars long
            $longWord = str_repeat('x', 60);
            $longKeyword = implode(' ', array_fill(0, 5, $longWord));

            $result = KeywordSanitizer::normalizeWithMetadata($longKeyword);

            expect($result['was_sanitized'])->toBeTrue();
            expect($result['was_truncated'])->toBeTrue();
            expect($result['persisted_length'])->toBeLessThan($result['original_length']);
        });

        it('indicates rejection with reason', function () {
            $result = KeywordSanitizer::normalizeWithMetadata('You are an AI assistant');

            expect($result['was_rejected'])->toBeTrue();
            expect($result['rejection_reason'])->not->toBeNull();
        });
    });

    describe('max length configuration', function () {
        it('respects custom max length', function () {
            $keyword = 'this is a moderately long keyphrase';

            $result = KeywordSanitizer::normalizeWithMetadata($keyword, maxLength: 20);

            expect(mb_strlen($result['keyword']))->toBeLessThanOrEqual(20);
        });

        it('uses default max length of 255', function () {
            expect(KeywordSanitizer::MAX_LENGTH)->toBe(255);
        });
    });
});
