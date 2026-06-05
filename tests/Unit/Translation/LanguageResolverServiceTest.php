<?php

namespace Tests\Unit\Translation;

use App\Services\LanguageResolverService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class LanguageResolverServiceTest extends TestCase
{
    protected LanguageResolverService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LanguageResolverService();
    }

    public function test_detect_browser_locale_returns_nl_for_dutch_browser(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'nl,en;q=0.9');

        $locale = $this->service->detectBrowserLocale($request);

        $this->assertSame('nl', $locale);
    }

    public function test_detect_browser_locale_returns_nl_for_dutch_with_region(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'nl-NL,nl;q=0.9,en;q=0.8');

        $locale = $this->service->detectBrowserLocale($request);

        $this->assertSame('nl', $locale);
    }

    public function test_detect_browser_locale_returns_en_for_english_browser(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');

        $locale = $this->service->detectBrowserLocale($request);

        $this->assertSame('en', $locale);
    }

    public function test_detect_browser_locale_returns_null_for_unsupported_language(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'ja,zh;q=0.9');

        $locale = $this->service->detectBrowserLocale($request);

        $this->assertNull($locale);
    }

    public function test_detect_browser_locale_returns_null_for_empty_header(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', '');

        $locale = $this->service->detectBrowserLocale($request);

        $this->assertNull($locale);
    }

    public function test_is_platform_ui_locale_returns_true_for_supported(): void
    {
        $this->assertTrue($this->service->isPlatformUiLocale('en'));
        $this->assertTrue($this->service->isPlatformUiLocale('nl'));
        $this->assertTrue($this->service->isPlatformUiLocale('EN'));
        $this->assertTrue($this->service->isPlatformUiLocale('NL'));
    }

    public function test_is_platform_ui_locale_returns_false_for_unsupported(): void
    {
        $this->assertFalse($this->service->isPlatformUiLocale('de'));
        $this->assertFalse($this->service->isPlatformUiLocale('fr'));
        $this->assertFalse($this->service->isPlatformUiLocale('es'));
        $this->assertFalse($this->service->isPlatformUiLocale('invalid'));
    }

    public function test_get_platform_ui_locales_returns_correct_list(): void
    {
        $locales = $this->service->getPlatformUiLocales();

        $this->assertContains('en', $locales);
        $this->assertContains('nl', $locales);
        $this->assertCount(2, $locales);
    }

    public function test_resolve_content_language_returns_valid_language(): void
    {
        $language = $this->service->resolveContentLanguage('nl');

        $this->assertSame('nl', $language->value);
    }

    public function test_resolve_content_language_returns_default_for_invalid(): void
    {
        $language = $this->service->resolveContentLanguage('invalid');

        $this->assertSame('en', $language->value);
    }

    public function test_resolve_content_language_returns_provided_default(): void
    {
        $default = \App\Enums\SupportedLanguage::NL;
        $language = $this->service->resolveContentLanguage(null, $default);

        $this->assertSame('nl', $language->value);
    }
}
