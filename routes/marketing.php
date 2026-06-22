<?php

/*
|--------------------------------------------------------------------------
| Marketing Subdomain Routes
|--------------------------------------------------------------------------
|
| These routes are loaded on the apex domain (argusly.local in dev,
| argusly.com in production). They serve public marketing pages.
|
*/

use App\Http\Controllers\LandingController;
use App\Http\Controllers\LegacyLocalizedMarketingRedirectController;
use App\Http\Controllers\MarketingPageController;
use App\Http\Controllers\PublicAgenticMarketingController;
use App\Http\Controllers\PublicAiDiscoveryController;
use App\Http\Controllers\PublicBlogController;
use App\Http\Controllers\PublicContactController;
use App\Http\Controllers\PublicEarlyAccessController;
use App\Http\Controllers\PublicEarlyAccessInviteController;
use App\Http\Controllers\PublicLegalController;
use App\Http\Controllers\PublicMarketController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PublicRobotsController;
use App\Http\Controllers\PublicSolutionController;
use App\Http\Controllers\SitemapController;
use App\Support\MarketingRouteSegments;
use Illuminate\Support\Facades\Route;

/** @var MarketingRouteSegments $marketingSegments */
$marketingSegments = app(MarketingRouteSegments::class);
$defaultMarketingLocale = $marketingSegments->defaultLocale();

Route::middleware('public.context')->group(function () use ($marketingSegments, $defaultMarketingLocale) {
    Route::get('/robots.txt', PublicRobotsController::class)->name('public.robots');
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemaps.index');
    Route::get('/sitemaps/{name}.xml', [SitemapController::class, 'show'])->name('sitemaps.show');
    Route::get('/{locale}/sitemap.xml', [SitemapController::class, 'index'])
        ->whereIn('locale', $marketingSegments->locales())
        ->name('sitemaps.localized.index');
    Route::get('/{locale}/sitemaps/{name}.xml', [SitemapController::class, 'show'])
        ->whereIn('locale', $marketingSegments->locales())
        ->name('sitemaps.localized.show');

    // Public marketing pages with locale support
    Route::middleware('public.locale')->group(function () use ($marketingSegments, $defaultMarketingLocale) {
        Route::get('/llms.txt', [PublicAiDiscoveryController::class, 'llms'])->name('public.ai.llms');
        Route::get('/llms-full.txt', [PublicAiDiscoveryController::class, 'llmsFull'])->name('public.ai.llms-full');

        // Legacy entry points now redirect to the canonical locale-prefixed URLs.
        Route::get('/', [LegacyLocalizedMarketingRedirectController::class, 'page'])->name('legacy.landing');
        Route::get('/contact', [LegacyLocalizedMarketingRedirectController::class, 'route'])
            ->defaults('marketing_route', 'public.company.contact')
            ->name('legacy.public.contact');
        Route::post('/contact', [PublicContactController::class, 'store'])
            ->middleware('throttle:contact')
            ->name('legacy.public.contact.submit');
        Route::get('/early-access', [LegacyLocalizedMarketingRedirectController::class, 'route'])
            ->defaults('marketing_route', 'public.early-access.show')
            ->defaults('legacy_locale', 'en')
            ->name('legacy.public.early-access.show');
        Route::get('/vroege-toegang', [LegacyLocalizedMarketingRedirectController::class, 'route'])
            ->defaults('marketing_route', 'public.early-access.show')
            ->defaults('legacy_locale', 'nl');
        Route::post('/early-access', [PublicEarlyAccessController::class, 'store'])
            ->middleware('throttle:contact')
            ->name('legacy.public.early-access.store');
        Route::get('/early-access/invites/{token}', [PublicEarlyAccessInviteController::class, 'show'])->name('legacy.public.early-access.invites.show');
        Route::post('/early-access/invites/{token}', [PublicEarlyAccessInviteController::class, 'store'])
            // Public invite submissions should stay usable while still absorbing bursts.
            ->middleware('throttle:contact')
            ->name('legacy.public.early-access.invites.store');

        // Pricing - blocked in early access mode
        Route::get('/prijzen', [LegacyLocalizedMarketingRedirectController::class, 'route'])
            ->defaults('marketing_route', 'pricing')
            ->defaults('legacy_locale', 'nl')
            ->name('legacy.pricing');
        Route::get('/pricing', [LegacyLocalizedMarketingRedirectController::class, 'route'])
            ->defaults('marketing_route', 'pricing')
            ->defaults('legacy_locale', 'en');

        // Blog routes - blocked in early access mode
        Route::prefix('blog')->name('public.blog.')->middleware('early-access.guard:blog')->group(function () {
            Route::get('/', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.blog.index')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.index');
            Route::get('/rss.xml', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.blog.rss')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.rss');
            Route::get('/tag/{tag}', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.blog.tag')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.tag');
            Route::get('/category/{category}', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.blog.category')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.category');
            Route::get('/{slug}.md', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.blog.markdown')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.markdown');
            Route::get('/{slug}', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.blog.show')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.show');
        });

        // Product pages
        Route::prefix('product')->name('public.product.')->group(function () {
            Route::get('/overview', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.product.overview')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.overview');
            Route::get('/platform', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.product.platform')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.platform');
            Route::get('/capabilities', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.product.capabilities')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.capabilities');
            Route::get('/governance', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.product.governance')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.governance');
            Route::get('/intelligence', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.product.intelligence')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.intelligence');
        });

        // Legacy market URLs now redirect to the canonical industries/sectoren paths.
        Route::prefix('markets')->name('public.markets.legacy.en.')->group(function () {
            foreach ((array) config('argusly_markets.pages', []) as $market => $page) {
                Route::get('/' . data_get($page, 'slugs.en', $market), [LegacyLocalizedMarketingRedirectController::class, 'route'])
                    ->defaults('marketing_route', 'public.markets.' . $market)
                    ->defaults('legacy_locale', 'en')
                    ->name($market);
            }
        });

        Route::prefix('markten')->name('public.markets.legacy.nl.')->group(function () {
            foreach ((array) config('argusly_markets.pages', []) as $market => $page) {
                Route::get('/' . data_get($page, 'slugs.nl', $market), [LegacyLocalizedMarketingRedirectController::class, 'route'])
                    ->defaults('marketing_route', 'public.markets.' . $market)
                    ->defaults('legacy_locale', 'nl')
                    ->name($market);
            }
        });

        // Company pages
        Route::prefix('company')->name('public.company.')->group(function () {
            Route::get('/about', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.company.about')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.about');
            Route::get('/contact', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.company.contact')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.contact');
            Route::post('/contact', [PublicContactController::class, 'store'])
                ->middleware('throttle:contact')
                ->name('legacy.contact.submit');
            Route::get('/roadmap', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.company.roadmap')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.roadmap');
        });

        // Legal pages - always accessible
        Route::prefix('legal')->name('public.legal.')->group(function () {
            Route::get('/', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.legal.index')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.index');
            Route::get('/privacy', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.legal.privacy')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.privacy');
            Route::get('/terms', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.legal.terms')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.terms');
            Route::get('/security', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.legal.security')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.security');
            Route::get('/cookies', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.legal.cookies')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.cookies');
            Route::get('/subprocessors', [LegacyLocalizedMarketingRedirectController::class, 'route'])
                ->defaults('marketing_route', 'public.legal.subprocessors')
                ->defaults('legacy_locale', 'en')
                ->name('legacy.subprocessors');
        });

        foreach ($marketingSegments->locales() as $locale) {
            $namePrefix = $locale === $defaultMarketingLocale ? '' : 'localized.' . $locale . '.';

            Route::prefix($locale)->name($namePrefix)->group(function () use ($locale, $marketingSegments) {
                Route::get('/', [LandingController::class, 'index'])->name('landing');

                Route::get('/' . $marketingSegments->segment('pricing', $locale), [LandingController::class, 'pricing'])
                    ->middleware('early-access.guard:pricing')
                    ->name('pricing');

                Route::get('/' . $marketingSegments->segment('early_access', $locale), [PublicEarlyAccessController::class, 'show'])
                    ->name('public.early-access.show');
                Route::post('/' . $marketingSegments->segment('early_access', $locale), [PublicEarlyAccessController::class, 'store'])
                    ->middleware('throttle:contact')
                    ->name('public.early-access.store');
                Route::get('/' . $marketingSegments->segment('early_access', $locale) . '/' . $marketingSegments->segment('invites', $locale) . '/{token}', [PublicEarlyAccessInviteController::class, 'show'])
                    ->name('public.early-access.invites.show');
                Route::post('/' . $marketingSegments->segment('early_access', $locale) . '/' . $marketingSegments->segment('invites', $locale) . '/{token}', [PublicEarlyAccessInviteController::class, 'store'])
                    ->middleware('throttle:contact')
                    ->name('public.early-access.invites.store');

                Route::get('/' . $marketingSegments->segment('agentic_marketing', $locale), PublicAgenticMarketingController::class)
                    ->name('public.agentic-marketing');

                foreach (['en' => 'markets', 'nl' => 'markten'] as $legacyLocale => $legacySegment) {
                    if ($locale !== $legacyLocale || $legacySegment === $marketingSegments->segment('markets', $locale)) {
                        continue;
                    }

                    Route::prefix($legacySegment)->name('public.markets.localized-legacy.' . $locale . '.')->group(function () use ($locale) {
                        foreach ((array) config('argusly_markets.pages', []) as $market => $page) {
                            Route::get('/' . data_get($page, 'slugs.' . $locale, $market), [LegacyLocalizedMarketingRedirectController::class, 'route'])
                                ->defaults('marketing_route', 'public.markets.' . $market)
                                ->defaults('legacy_locale', $locale)
                                ->name($market);
                        }
                    });
                }

                Route::prefix($marketingSegments->segment('markets', $locale))->name('public.markets.')->group(function () use ($locale) {
                    foreach ((array) config('argusly_markets.pages', []) as $market => $page) {
                        Route::get('/' . data_get($page, 'slugs.' . $locale, $market), PublicMarketController::class)
                            ->defaults('market', $market)
                            ->name($market);
                    }
                });

                Route::prefix($marketingSegments->segment('solutions', $locale))->name('public.solutions.')->group(function () use ($locale, $marketingSegments) {
                    Route::get('/' . $marketingSegments->segment('opportunity_intelligence', $locale), [PublicSolutionController::class, 'show'])
                        ->defaults('solution', 'opportunity-intelligence')
                        ->name('opportunity-intelligence');
                    Route::get('/' . $marketingSegments->segment('ai_visibility', $locale), [PublicSolutionController::class, 'show'])
                        ->defaults('solution', 'ai-visibility')
                        ->name('ai-visibility');
                    Route::get('/' . $marketingSegments->segment('competitive_intelligence', $locale), [PublicSolutionController::class, 'show'])
                        ->defaults('solution', 'competitive-intelligence')
                        ->name('competitive-intelligence');
                    Route::get('/' . $marketingSegments->segment('marketing_without_large_team', $locale), [PublicSolutionController::class, 'show'])
                        ->defaults('solution', 'marketing-without-large-team')
                        ->name('marketing-without-large-team');
                });

                Route::prefix($marketingSegments->segment('blog', $locale))
                    ->name('public.blog.')
                    ->middleware('early-access.guard:blog')
                    ->group(function () use ($locale, $marketingSegments) {
                        Route::get('/', [PublicBlogController::class, 'index'])->name('index');
                        Route::get('/rss.xml', [PublicBlogController::class, 'rss'])->name('rss');
                        Route::get('/' . $marketingSegments->segment('tag', $locale) . '/{tag}', [PublicBlogController::class, 'tag'])->name('tag');
                        Route::get('/' . $marketingSegments->segment('category', $locale) . '/{category}', [PublicBlogController::class, 'category'])->name('category');
                        Route::get('/{slug}.md', [PublicAiDiscoveryController::class, 'blogMarkdown'])->name('markdown');
                        Route::get('/{slug}', [PublicBlogController::class, 'show'])->name('show');
                    });

                Route::prefix($marketingSegments->segment('product', $locale))->name('public.product.')->group(function () use ($locale, $marketingSegments) {
                    Route::get('/' . $marketingSegments->segment('overview', $locale), [PublicPageController::class, 'show'])
                        ->defaults('key', 'product.overview')
                        ->defaults('marketing_route', 'public.product.overview')
                        ->name('overview');

                    Route::get('/' . $marketingSegments->segment('platform', $locale), [PublicPageController::class, 'show'])
                        ->defaults('key', 'product.platform')
                        ->defaults('marketing_route', 'public.product.platform')
                        ->middleware('early-access.guard:product.capabilities')
                        ->name('platform');

                    Route::get('/' . $marketingSegments->segment('capabilities', $locale), [PublicPageController::class, 'redirectLegacyProduct'])
                        ->defaults('anchor', 'capabilities')
                        ->defaults('marketing_route', 'public.product.capabilities')
                        ->middleware('early-access.guard:product.capabilities')
                        ->name('capabilities');
                    Route::get('/' . $marketingSegments->segment('governance', $locale), [PublicPageController::class, 'redirectLegacyProduct'])
                        ->defaults('anchor', 'governance')
                        ->defaults('marketing_route', 'public.product.governance')
                        ->middleware('early-access.guard:product.governance')
                        ->name('governance');
                    Route::get('/' . $marketingSegments->segment('intelligence', $locale), [PublicPageController::class, 'redirectLegacyProduct'])
                        ->defaults('anchor', 'intelligence')
                        ->defaults('marketing_route', 'public.product.intelligence')
                        ->middleware('early-access.guard:product.intelligence')
                        ->name('intelligence');
                });

                Route::prefix($marketingSegments->segment('company', $locale))->name('public.company.')->group(function () use ($locale, $marketingSegments) {
                    Route::get('/' . $marketingSegments->segment('about', $locale), [PublicPageController::class, 'show'])
                        ->defaults('key', 'company.about')
                        ->defaults('marketing_route', 'public.company.about')
                        ->name('about');
                    Route::get('/' . $marketingSegments->segment('contact', $locale), [PublicPageController::class, 'show'])
                        ->defaults('key', 'company.contact')
                        ->defaults('marketing_route', 'public.company.contact')
                        ->name('contact');
                    Route::post('/' . $marketingSegments->segment('contact', $locale), [PublicContactController::class, 'store'])
                        ->middleware('throttle:contact')
                        ->name('contact.submit');
                    Route::get('/' . $marketingSegments->segment('roadmap', $locale), [PublicPageController::class, 'show'])
                        ->defaults('key', 'company.roadmap')
                        ->defaults('marketing_route', 'public.company.roadmap')
                        ->middleware('early-access.guard:company.roadmap')
                        ->name('roadmap');
                });

                Route::prefix($marketingSegments->segment('legal', $locale))->name('public.legal.')->group(function () use ($locale, $marketingSegments) {
                    Route::get('/', [PublicLegalController::class, 'hub'])
                        ->defaults('marketing_route', 'public.legal.index')
                        ->name('index');
                    Route::get('/' . $marketingSegments->segment('privacy', $locale), [PublicLegalController::class, 'show'])
                        ->defaults('page', 'privacy')
                        ->defaults('marketing_route', 'public.legal.privacy')
                        ->name('privacy');
                    Route::get('/' . $marketingSegments->segment('terms', $locale), [PublicLegalController::class, 'show'])
                        ->defaults('page', 'terms')
                        ->defaults('marketing_route', 'public.legal.terms')
                        ->name('terms');
                    Route::get('/' . $marketingSegments->segment('security', $locale), [PublicLegalController::class, 'show'])
                        ->defaults('page', 'security')
                        ->defaults('marketing_route', 'public.legal.security')
                        ->name('security');
                    Route::get('/' . $marketingSegments->segment('cookies', $locale), [PublicLegalController::class, 'show'])
                        ->defaults('page', 'cookies')
                        ->defaults('marketing_route', 'public.legal.cookies')
                        ->name('cookies');
                Route::get('/' . $marketingSegments->segment('subprocessors', $locale), [PublicLegalController::class, 'show'])
                        ->defaults('page', 'subprocessors')
                        ->defaults('marketing_route', 'public.legal.subprocessors')
                        ->name('subprocessors');
                });

                Route::get('/{slug}.md', [MarketingPageController::class, 'markdown'])
                    ->where('slug', '[A-Za-z0-9\-_]+')
                    ->defaults('marketing_route', 'public.marketing-pages.markdown')
                    ->name('public.marketing-pages.markdown');
                Route::get('/{slug}', [MarketingPageController::class, 'show'])
                    ->where('slug', '[A-Za-z0-9\-_]+')
                    ->defaults('marketing_route', 'public.marketing-pages.show')
                    ->name('public.marketing-pages.show');
            });
        }
    });
});

// Auth routes on marketing domain for backwards compatibility
// These allow existing bookmarks and forms to continue working
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\EmailCodeVerificationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\App\AppSettingsController;
use App\Http\Controllers\Billing\PackReturnController;
use App\Http\Controllers\Impersonation\StopImpersonationController;

Route::get('/login', [LoginController::class, 'show'])
    ->middleware('guest')
    ->name('marketing.login');
Route::post('/login', [LoginController::class, 'store'])
    ->middleware(['guest', 'throttle:login']);
Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth');
Route::post('/impersonation/stop', StopImpersonationController::class)
    ->middleware('auth');

Route::get('/register', [RegisterController::class, 'show'])
    ->middleware('registration.enabled')
    ->name('marketing.register');
Route::post('/register', [RegisterController::class, 'store'])
    ->middleware(['registration.enabled', 'throttle:organization-register']);
Route::get('/verify-code', [EmailCodeVerificationController::class, 'show'])
    ->middleware('auth');
Route::post('/verify-code', [EmailCodeVerificationController::class, 'verify'])
    ->middleware('auth');
Route::post('/verify-code/resend', [EmailCodeVerificationController::class, 'resend'])
    ->middleware('auth');

Route::view('/pending', 'auth.pending');
Route::view('/on-hold', 'auth.on-hold');
Route::get('/invite/{token}', [AppSettingsController::class, 'acceptForm']);
Route::post('/invite/{token}', [AppSettingsController::class, 'accept']);

// Legacy billing return callback support on apex domain.
Route::get('/billing/return', [PackReturnController::class, 'handle']);
