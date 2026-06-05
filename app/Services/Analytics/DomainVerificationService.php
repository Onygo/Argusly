<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsSite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class DomainVerificationService
{
    private const META_NAME = 'publishlayer-site-verification';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Check if a domain is an internal verified domain from config.
     */
    public function isInternalVerifiedDomain(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        $internalDomains = $this->getInternalVerifiedDomains();

        return in_array($host, $internalDomains, true);
    }

    /**
     * Get the list of internal verified domains from config.
     *
     * @return array<int, string>
     */
    public function getInternalVerifiedDomains(): array
    {
        $domains = config('publishlayer.analytics.internal_verified_domains', []);

        if (! is_array($domains)) {
            return [];
        }

        return array_map('strtolower', array_filter(array_map('trim', $domains)));
    }

    /**
     * Verify an internal domain without meta tag check.
     */
    public function verifyInternal(AnalyticsSite $site): VerificationResult
    {
        $site->loadMissing('clientSite.workspace.organization');
        $clientSite = $site->clientSite;
        $url = rtrim((string) ($clientSite?->site_url ?? ''), '/').'/';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $context = $this->buildLogContext($site, $clientSite?->id, $url, $host);

        if (! $site->is_enabled) {
            Log::warning('analytics.domain_verification.internal.analytics_disabled', $context);

            return VerificationResult::failure(
                message: 'Analytics is disabled. Enable analytics before verifying.',
                code: 'analytics_disabled',
                retryable: false,
                action: 'enable_analytics'
            );
        }

        if (! $this->isInternalVerifiedDomain($host)) {
            Log::warning('analytics.domain_verification.internal.not_internal_domain', $context);

            return VerificationResult::failure(
                message: 'This domain is not an internal verified domain.',
                code: 'not_internal_domain',
                retryable: false
            );
        }

        // Mark as internally verified
        $site->markInternallyVerified($host);

        Log::info('analytics.domain_verification.internal.verified', $context);

        return VerificationResult::success('Domain verified as first-party internal domain');
    }

    /**
     * Verify a domain by checking for the meta tag.
     * For internal domains, auto-verifies without meta tag check.
     */
    public function verify(AnalyticsSite $site): VerificationResult
    {
        $site->loadMissing('clientSite.workspace.organization');
        $clientSite = $site->clientSite;
        $url = rtrim((string) ($clientSite?->site_url ?? ''), '/').'/';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $context = $this->buildLogContext($site, $clientSite?->id, $url, $host);

        Log::info('analytics.domain_verification.started', $context);

        // Check if this is an internal domain - auto-verify if so
        if ($this->isInternalVerifiedDomain($host)) {
            return $this->verifyInternal($site);
        }

        if (! $site->is_enabled) {
            Log::warning('analytics.domain_verification.analytics_disabled', $context);

            return VerificationResult::failure(
                message: 'Analytics is disabled. Enable analytics before verifying.',
                code: 'analytics_disabled',
                retryable: false,
                action: 'enable_analytics'
            );
        }

        if (! $clientSite || ! $clientSite->site_url) {
            Log::warning('analytics.domain_verification.site_url_missing', $context);

            return VerificationResult::failure(
                message: 'This site is missing a valid site URL. Review site setup and try again.',
                code: 'site_url_missing',
                retryable: false,
                action: 'review_site_settings'
            );
        }

        if (trim((string) $site->verification_token) === '') {
            Log::warning('analytics.domain_verification.verification_token_missing', $context);

            return VerificationResult::failure(
                message: 'Verification token is missing. Regenerate the token and try again.',
                code: 'verification_token_missing',
                retryable: false,
                action: 'regenerate_token'
            );
        }

        if ($host === '') {
            Log::warning('analytics.domain_verification.invalid_site_url', $context);

            return VerificationResult::failure(
                message: 'This site URL is invalid. Review site setup and try again.',
                code: 'invalid_site_url',
                retryable: false,
                action: 'review_site_settings'
            );
        }

        try {
            $disableTlsVerify = $this->shouldDisableTlsVerifyFor($url);
            $request = Http::accept('text/html')
                ->timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent('PublishLayer-Verification/1.0');

            if ($disableTlsVerify) {
                // Local development workaround for self-signed certs; do not enable in production.
                $request = $request->withoutVerifying();

                Log::debug('TLS verify disabled for domain verification fetch', [
                    'host' => $host,
                    'env' => app()->environment(),
                ]);
            }

            Log::info('analytics.domain_verification.requesting', array_merge($context, [
                'request_type' => 'GET',
                'tls_verify_disabled' => $disableTlsVerify,
            ]));

            $response = $request->get($url);

            Log::info('analytics.domain_verification.response', array_merge($context, [
                'request_type' => 'GET',
                'response_status' => $response->status(),
            ]));

            if (! $response->successful()) {
                Log::warning('analytics.domain_verification.http_failure', array_merge($context, [
                    'request_type' => 'GET',
                    'response_status' => $response->status(),
                ]));

                return VerificationResult::failure(
                    message: "We could not verify the domain right now. The site returned HTTP {$response->status()}.",
                    code: 'http_error',
                    responseStatus: $response->status(),
                    retryable: true,
                    action: 'retry_verification'
                );
            }

            $html = $response->body();
            $token = $this->extractVerificationToken($html);

            if ($token === null) {
                Log::warning('analytics.domain_verification.meta_tag_missing', $context);

                return VerificationResult::failure(
                    message: 'Verification meta tag not found. Add the tag shown below to your site head and retry.',
                    code: 'verification_meta_missing',
                    retryable: true,
                    action: 'retry_verification'
                );
            }

            if ($token !== $site->verification_token) {
                Log::warning('analytics.domain_verification.token_mismatch', $context);

                return VerificationResult::failure(
                    message: 'Verification token mismatch. Update the tag on your site or regenerate the token.',
                    code: 'verification_token_mismatch',
                    retryable: false,
                    action: 'regenerate_token'
                );
            }

            // Success - mark as verified
            $site->markVerified();

            Log::info('analytics.domain_verification.verified', $context);

            return VerificationResult::success('Domain verified successfully');
        } catch (\Throwable $e) {
            $errorMessage = $this->sanitizeErrorMessage($e->getMessage());
            $code = $e instanceof RuntimeException ? 'verification_configuration_error' : 'verification_request_failed';
            $retryable = ! $e instanceof RuntimeException;
            $action = $retryable ? 'retry_verification' : null;

            Log::warning('analytics.domain_verification.exception', array_merge($context, [
                'request_type' => 'GET',
                'response_status' => null,
                'error' => $errorMessage,
                'exception_class' => $e::class,
            ]));

            return VerificationResult::failure(
                message: $e instanceof RuntimeException
                    ? 'Verification is temporarily unavailable because the server verification configuration is invalid.'
                    : 'We could not connect to the site to verify it. Please retry in a moment.',
                code: $code,
                retryable: $retryable,
                action: $action,
                details: $errorMessage
            );
        }
    }

    /**
     * Manually verify a site (admin override).
     */
    public function verifyManually(AnalyticsSite $site, string $adminEmail): void
    {
        $site->verified_at = now();
        $site->flags = array_merge($site->flags ?? [], [
            'manually_verified' => true,
            'verified_by' => $adminEmail,
            'verified_at' => now()->toIso8601String(),
        ]);
        $site->save();

        Log::info('Analytics site manually verified', [
            'analytics_site_id' => $site->id,
            'admin' => $adminEmail,
        ]);
    }

    /**
     * Extract the verification token from HTML.
     */
    private function extractVerificationToken(string $html): ?string
    {
        // Match: <meta name="publishlayer-site-verification" content="TOKEN">
        $pattern = '/<meta\s+[^>]*name=["\']'.preg_quote(self::META_NAME, '/').'["\'][^>]*content=["\']([^"\']+)["\']/i';

        if (preg_match($pattern, $html, $matches)) {
            return trim($matches[1]);
        }

        // Try alternate order: content before name
        $pattern = '/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']'.preg_quote(self::META_NAME, '/').'/i';

        if (preg_match($pattern, $html, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function shouldDisableTlsVerifyFor(string $url): bool
    {
        if ($this->isProductionEnvironment()) {
            if (config('publishlayer.http_insecure_local') === true) {
                throw new RuntimeException('PUBLISHLAYER_HTTP_INSECURE_LOCAL must never be enabled in production.');
            }

            return false;
        }

        if (! $this->isLocalDevelopmentEnvironment()) {
            return false;
        }

        if (config('publishlayer.http_insecure_local') !== true) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $this->isLocalHost($host);
    }

    private function isLocalDevelopmentEnvironment(): bool
    {
        if (app()->environment(['local', 'development'])) {
            return true;
        }

        return in_array(strtolower((string) config('app.env', '')), ['local', 'development', 'dev'], true);
    }

    private function isProductionEnvironment(): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        return strtolower((string) config('app.env', '')) === 'production';
    }

    private function isLocalHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (in_array($host, ['publishlayer.local', 'localhost', '127.0.0.1'], true)) {
            return true;
        }

        return str_ends_with($host, '.local');
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLogContext(AnalyticsSite $site, mixed $clientSiteId, string $url, string $host): array
    {
        return [
            'analytics_site_id' => (string) $site->id,
            'client_site_id' => is_scalar($clientSiteId) ? (string) $clientSiteId : null,
            'workspace_id' => (string) ($site->clientSite?->workspace_id ?? ''),
            'organization_id' => (string) ($site->clientSite?->workspace?->organization_id ?? ''),
            'domain' => $host !== '' ? $host : null,
            'url' => $url !== '/' ? $url : null,
        ];
    }

    private function sanitizeErrorMessage(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message);

        return Str::limit($message, 180);
    }
}

class VerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?string $code = null,
        public readonly ?int $responseStatus = null,
        public readonly bool $retryable = false,
        public readonly ?string $action = null,
        public readonly ?string $details = null,
    ) {
    }

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(
        string $message,
        ?string $code = null,
        ?int $responseStatus = null,
        bool $retryable = false,
        ?string $action = null,
        ?string $details = null,
    ): self {
        return new self(false, $message, $code, $responseStatus, $retryable, $action, $details);
    }
}
