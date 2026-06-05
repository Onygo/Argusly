<?php

use App\Services\Llm\LlmJsonNormalizer;

describe('LlmJsonNormalizer UTF-8 handling', function () {
    beforeEach(function () {
        $this->normalizer = new LlmJsonNormalizer();
    });

    it('preserves Dutch accented characters in JSON', function () {
        $json = '{"text": "één, oriënteren, technologieën, definiëren"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['text'])->toBe('één, oriënteren, technologieën, definiëren');
    });

    it('preserves E-E-A-T with hyphens', function () {
        $json = '{"concept": "E-E-A-T guidelines"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['concept'])->toBe('E-E-A-T guidelines');
    });

    it('preserves arrow characters in text', function () {
        $json = '{"flow": "SEO → GEO → AI visibility"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['flow'])->toBe('SEO → GEO → AI visibility');
    });

    it('preserves the full test string without corruption', function () {
        $testString = 'één, oriënteren, technologieën, definiëren, E-E-A-T, SEO → GEO → AI visibility';
        $json = json_encode(['content' => $testString], JSON_UNESCAPED_UNICODE);

        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['content'])->toBe($testString);
    });

    it('handles smart punctuation correctly', function () {
        // Smart quotes are valid UTF-8 characters that should be preserved
        $text = 'This is "quoted" text with — em dashes and … ellipsis';
        $json = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['text'])->toBe($text);
    });

    it('preserves German umlauts', function () {
        $json = '{"text": "Müller, Größe, Übung, Ärger"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['text'])->toBe('Müller, Größe, Übung, Ärger');
    });

    it('preserves French accents', function () {
        $json = '{"text": "café, résumé, naïve, façade"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['text'])->toBe('café, résumé, naïve, façade');
    });

    it('preserves emoji characters', function () {
        $json = '{"text": "Great job! 🎉 Keep going 💪"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['text'])->toBe('Great job! 🎉 Keep going 💪');
    });

    it('handles mixed content with newlines correctly', function () {
        $json = '{"content": "Line 1\nLine 2 with één\nLine 3 with →"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['content'])->toBe("Line 1\nLine 2 with één\nLine 3 with →");
    });

    it('repairs JSON with unescaped control characters while preserving UTF-8', function () {
        // JSON with a tab character that should be escaped
        $json = '{"text": "Value with' . "\t" . 'tab and één"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['text'])->toContain('één');
    });

    it('handles deeply nested JSON with UTF-8', function () {
        $json = '{"level1": {"level2": {"text": "Nested één → GEO"}}}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect(data_get($decoded, 'level1.level2.text'))->toBe('Nested één → GEO');
    });

    it('handles JSON with LLM markdown code fences containing UTF-8', function () {
        $input = "Here's the JSON:\n```json\n{\"text\": \"één → GEO\"}\n```";
        $decoded = $this->normalizer->decode($input);

        expect($decoded)->toBeArray();
        expect($decoded['text'])->toBe('één → GEO');
    });

    it('handles arrays with UTF-8 strings', function () {
        $json = '{"items": ["één", "twee", "oriënteren", "→"]}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['items'])->toBe(['één', 'twee', 'oriënteren', '→']);
    });

    it('extracts field value preserving UTF-8', function () {
        $json = '{"title": "Wat is E-E-A-T?", "body": "SEO → GEO"}';
        $title = $this->normalizer->extractFieldValue($json, 'title');
        $body = $this->normalizer->extractFieldValue($json, 'body');

        expect($title)->toBe('Wat is E-E-A-T?');
        expect($body)->toBe('SEO → GEO');
    });

    it('handles truncated JSON with UTF-8 content', function () {
        $truncatedJson = '{"title": "één", "content": "oriënt';

        expect($this->normalizer->isTruncatedJson($truncatedJson))->toBeTrue();

        // The decoder should attempt completion
        $decoded = $this->normalizer->decode($truncatedJson);

        // Even if completion fails, the method should not corrupt existing UTF-8
        if ($decoded !== null) {
            expect($decoded['title'])->toBe('één');
        }
    });

    it('preserves special symbols and mathematical operators', function () {
        $json = '{"formula": "a ≠ b, x ≤ y, α → β"}';
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        expect($decoded['formula'])->toBe('a ≠ b, x ≤ y, α → β');
    });

    it('handles Windows-style line endings with UTF-8', function () {
        $json = "{\"text\": \"één\r\ntwee\r\ndrie\"}";
        $decoded = $this->normalizer->decode($json);

        expect($decoded)->toBeArray();
        // Windows line endings are normalized to \n
        expect($decoded['text'])->toContain('één');
    });
});
