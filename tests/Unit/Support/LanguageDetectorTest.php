<?php

use App\Enums\SupportedLanguage;
use App\Support\LanguageDetector;

describe('LanguageDetector', function () {
    beforeEach(function () {
        $this->detector = new LanguageDetector();
    });

    describe('detect', function () {
        it('detects Dutch text correctly', function () {
            $dutchText = 'Dit is een test van de Nederlandse taal. We gaan kijken of het systeem het goed detecteert. Er zijn veel woorden nodig om een goede detectie te krijgen.';

            $result = $this->detector->detect($dutchText);

            expect($result['language'])->toBe(SupportedLanguage::NL);
            expect($result['confidence'])->toBeGreaterThan(0.6);
        });

        it('detects English text correctly', function () {
            $englishText = 'This is a test of the English language. We are going to see if the system detects it correctly. Many words are needed for good detection.';

            $result = $this->detector->detect($englishText);

            expect($result['language'])->toBe(SupportedLanguage::EN);
            expect($result['confidence'])->toBeGreaterThan(0.6);
        });

        it('returns null for insufficient text', function () {
            $shortText = 'Hello world';

            $result = $this->detector->detect($shortText);

            expect($result['language'])->toBeNull();
            expect($result['confidence'])->toBe(0.0);
        });

        it('handles mixed language text with Dutch markers', function () {
            $mixedText = 'De marketing strategie is gebaseerd op het SEO framework. Het team heeft verschillende implementaties gedaan voor de nieuwe features.';

            $result = $this->detector->detect($mixedText);

            // Should lean Dutch due to articles and pronouns
            expect($result['dutch_score'])->toBeGreaterThan($result['english_score']);
        });
    });

    describe('isDutch', function () {
        it('returns true for Dutch text', function () {
            $dutchText = 'Dit is een uitgebreide Nederlandse tekst met veel woorden. We hebben deze tekst nodig om te testen of de detectie correct werkt.';

            expect($this->detector->isDutch($dutchText))->toBeTrue();
        });

        it('returns false for English text', function () {
            $englishText = 'This is an extensive English text with many words. We need this text to test if the detection works correctly.';

            expect($this->detector->isDutch($englishText))->toBeFalse();
        });
    });

    describe('isEnglish', function () {
        it('returns true for English text', function () {
            $englishText = 'This is an extensive English text with many words. We need this text to test if the detection works correctly.';

            expect($this->detector->isEnglish($englishText))->toBeTrue();
        });

        it('returns false for Dutch text', function () {
            $dutchText = 'Dit is een uitgebreide Nederlandse tekst met veel woorden. We hebben deze tekst nodig om te testen of de detectie correct werkt.';

            expect($this->detector->isEnglish($dutchText))->toBeFalse();
        });
    });

    describe('detectMismatch', function () {
        it('detects mismatch when Dutch text is marked as English', function () {
            $dutchText = 'Dit is een uitgebreide Nederlandse tekst met veel woorden. We hebben deze tekst nodig om te testen of de detectie correct werkt.';

            $result = $this->detector->detectMismatch($dutchText, 'en');

            expect($result['is_mismatched'])->toBeTrue();
            expect($result['declared_locale'])->toBe('en');
            expect($result['detected_language'])->toBe(SupportedLanguage::NL);
            expect($result['suggested_locale'])->toBe('nl');
        });

        it('detects mismatch when English text is marked as Dutch', function () {
            $englishText = 'This is an extensive English text with many words. We need this text to test if the detection works correctly.';

            $result = $this->detector->detectMismatch($englishText, 'nl');

            expect($result['is_mismatched'])->toBeTrue();
            expect($result['declared_locale'])->toBe('nl');
            expect($result['detected_language'])->toBe(SupportedLanguage::EN);
            expect($result['suggested_locale'])->toBe('en');
        });

        it('returns no mismatch when locales match', function () {
            $dutchText = 'Dit is een uitgebreide Nederlandse tekst met veel woorden. We hebben deze tekst nodig om te testen.';

            $result = $this->detector->detectMismatch($dutchText, 'nl');

            expect($result['is_mismatched'])->toBeFalse();
            expect($result['suggested_locale'])->toBeNull();
        });

        it('handles case insensitive locale comparison', function () {
            $englishText = 'This is an extensive English text with many words. We need this text to test if the detection works.';

            $result = $this->detector->detectMismatch($englishText, 'EN');

            expect($result['is_mismatched'])->toBeFalse();
            expect($result['declared_locale'])->toBe('en');
        });
    });

    describe('Dutch pattern detection', function () {
        it('recognizes Dutch diacritics', function () {
            $textWithDiacritics = 'Een uitgebreide tekst met speciale tekens zoals één, twéé en naïef. Dit helpt bij de detectie van Nederlandse tekst.';

            $result = $this->detector->detect($textWithDiacritics);

            // Dutch diacritics should boost the Dutch score
            expect($result['dutch_score'])->toBeGreaterThan(0);
        });

        it('recognizes Dutch word endings', function () {
            $textWithEndings = 'De belangrijkheid van deze ontwikkeling is groot. De leiderschap toont vrijheid in het bestuur van de organisatie.';

            $result = $this->detector->detect($textWithEndings);

            expect($result['language'])->toBe(SupportedLanguage::NL);
        });
    });
});
