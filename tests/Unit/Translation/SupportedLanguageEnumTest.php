<?php

namespace Tests\Unit\Translation;

use App\Enums\SupportedLanguage;
use PHPUnit\Framework\TestCase;

class SupportedLanguageEnumTest extends TestCase
{
    public function test_all_supported_languages_have_values(): void
    {
        $values = SupportedLanguage::values();

        $this->assertContains('en', $values);
        $this->assertContains('nl', $values);
        $this->assertContains('de', $values);
        $this->assertContains('fr', $values);
        $this->assertContains('es', $values);
        $this->assertCount(5, $values);
    }

    public function test_default_language_is_english(): void
    {
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::default());
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::platformDefault());
    }

    public function test_from_browser_locale_returns_correct_language(): void
    {
        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::fromBrowserLocale('nl'));
        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::fromBrowserLocale('nl-NL'));
        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::fromBrowserLocale('NL'));

        $this->assertSame(SupportedLanguage::DE, SupportedLanguage::fromBrowserLocale('de'));
        $this->assertSame(SupportedLanguage::DE, SupportedLanguage::fromBrowserLocale('de-DE'));

        $this->assertSame(SupportedLanguage::FR, SupportedLanguage::fromBrowserLocale('fr'));
        $this->assertSame(SupportedLanguage::FR, SupportedLanguage::fromBrowserLocale('fr-FR'));

        $this->assertSame(SupportedLanguage::ES, SupportedLanguage::fromBrowserLocale('es'));
        $this->assertSame(SupportedLanguage::ES, SupportedLanguage::fromBrowserLocale('es-ES'));
    }

    public function test_from_browser_locale_falls_back_to_english(): void
    {
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromBrowserLocale('en'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromBrowserLocale('en-US'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromBrowserLocale('en-GB'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromBrowserLocale('ja'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromBrowserLocale('zh'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromBrowserLocale('unknown'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromBrowserLocale(''));
    }

    public function test_try_from_string_handles_various_inputs(): void
    {
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::tryFromString('en'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::tryFromString('EN'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::tryFromString(' en '));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::tryFromString('en-US'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::tryFromString('en_GB'));

        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::tryFromString('nl'));
        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::tryFromString('NL'));
        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::tryFromString('nl-NL'));
        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::tryFromString('nl_BE'));

        $this->assertNull(SupportedLanguage::tryFromString(''));
        $this->assertNull(SupportedLanguage::tryFromString(null));
        $this->assertNull(SupportedLanguage::tryFromString('invalid'));
    }

    public function test_from_string_or_default_returns_default_for_invalid(): void
    {
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromStringOrDefault(null));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromStringOrDefault(''));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromStringOrDefault('invalid'));
        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::fromStringOrDefault('nl'));
        $this->assertSame(SupportedLanguage::NL, SupportedLanguage::fromStringOrDefault('nl-NL'));
        $this->assertSame(SupportedLanguage::EN, SupportedLanguage::fromStringOrDefault('en-US'));
    }

    public function test_normalize_locale_returns_base_language_codes(): void
    {
        $this->assertSame('nl', SupportedLanguage::normalizeLocale(' nl-NL '));
        $this->assertSame('nl', SupportedLanguage::normalizeLocale('nl_BE'));
        $this->assertSame('en', SupportedLanguage::normalizeLocale('en-US'));
        $this->assertSame('en', SupportedLanguage::normalizeLocale('EN_gb'));
        $this->assertNull(SupportedLanguage::normalizeLocale(''));
        $this->assertNull(SupportedLanguage::normalizeLocale(null));
    }

    public function test_languages_have_labels(): void
    {
        $this->assertSame('English', SupportedLanguage::EN->label());
        $this->assertSame('Nederlands', SupportedLanguage::NL->label());
        $this->assertSame('Deutsch', SupportedLanguage::DE->label());
        $this->assertSame('Français', SupportedLanguage::FR->label());
        $this->assertSame('Español', SupportedLanguage::ES->label());
    }

    public function test_languages_have_english_labels(): void
    {
        $this->assertSame('English', SupportedLanguage::EN->englishLabel());
        $this->assertSame('Dutch', SupportedLanguage::NL->englishLabel());
        $this->assertSame('German', SupportedLanguage::DE->englishLabel());
        $this->assertSame('French', SupportedLanguage::FR->englishLabel());
        $this->assertSame('Spanish', SupportedLanguage::ES->englishLabel());
    }

    public function test_languages_have_flags(): void
    {
        foreach (SupportedLanguage::cases() as $language) {
            $this->assertNotEmpty($language->flag());
        }
    }

    public function test_options_returns_complete_array(): void
    {
        $options = SupportedLanguage::options();

        $this->assertCount(5, $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('englishLabel', $option);
            $this->assertArrayHasKey('flag', $option);
        }
    }

    public function test_is_rtl_returns_false_for_all_current_languages(): void
    {
        foreach (SupportedLanguage::cases() as $language) {
            $this->assertFalse($language->isRtl());
        }
    }
}
