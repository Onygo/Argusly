<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Services\Analytics\DomainVerificationService;
use App\Services\Analytics\SiteAnalyticsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AppAnalyticsSiteController extends Controller
{
    public function __construct(
        private DomainVerificationService $verificationService,
        private SiteAnalyticsService $siteAnalyticsService
    ) {
    }

    public function show(Request $request, ClientSite $site): View
    {
        $user = $request->user();
        $this->authorizeSiteAccess($site, $user);

        $analyticsSite = $site->analyticsSite;
        $trackingBaseUrl = $this->getTrackingBaseUrl();
        $scriptVersion = (string) config('publishlayer.tracking_script_version', config('analytics.script.version', '1.1.0'));
        $trackingSnippet = $this->buildTrackingSnippet($analyticsSite, $trackingBaseUrl, $scriptVersion);
        $scope = $this->siteAnalyticsService->normalizeScope(
            (string) $request->query('scope', SiteAnalyticsService::SCOPE_PUBLISHLAYER_CONTENT)
        );
        $stats = $this->buildStats($analyticsSite, $scope);

        return view('app.sites.analytics.show', [
            'site' => $site,
            'analyticsSite' => $analyticsSite,
            'trackingBaseUrl' => $trackingBaseUrl,
            'scriptVersion' => $scriptVersion,
            'trackingSnippet' => $trackingSnippet,
            'stats' => $stats,
            'scope' => $scope,
        ]);
    }

    public function enable(Request $request, ClientSite $site): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeSiteAccess($site, $user);

        // Create or enable analytics site
        $analyticsSite = $site->analyticsSite;

        if (! $analyticsSite) {
            $analyticsSite = AnalyticsSite::create([
                'client_site_id' => $site->id,
                'allowed_domains' => $site->allowed_domains ?? [],
                'is_enabled' => true,
            ]);
        } else {
            $analyticsSite->is_enabled = true;
            $analyticsSite->save();
        }

        return redirect()
            ->route('app.sites.analytics.show', $site)
            ->with('status', 'Analytics enabled. Please verify your domain to start tracking.');
    }

    public function disable(Request $request, ClientSite $site): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeSiteAccess($site, $user);

        $analyticsSite = $site->analyticsSite;

        if ($analyticsSite) {
            $analyticsSite->is_enabled = false;
            $analyticsSite->save();
        }

        return redirect()
            ->route('app.sites.analytics.show', $site)
            ->with('status', 'Analytics disabled.');
    }

    public function verify(Request $request, ClientSite $site): RedirectResponse
    {
        $user = $request->user();
        $site->loadMissing('workspace.organization', 'analyticsSite');

        Log::info('analytics.verify.route_entry', $this->buildVerificationLogContext(
            request: $request,
            site: $site,
            analyticsSite: $site->analyticsSite,
        ));

        $this->authorizeSiteAccess($site, $user);

        $analyticsSite = $site->analyticsSite;

        if (! $analyticsSite) {
            Log::warning('analytics.verify.analytics_site_missing', $this->buildVerificationLogContext(
                request: $request,
                site: $site,
                analyticsSite: null,
            ));

            return redirect()
                ->route('app.sites.analytics.show', $site)
                ->with($this->buildVerificationFailureFlash(
                    message: 'Please enable analytics first.',
                    action: 'enable_analytics'
                ));
        }

        try {
            Log::info('analytics.verify.service_call', $this->buildVerificationLogContext(
                request: $request,
                site: $site,
                analyticsSite: $analyticsSite,
            ));

            $result = $this->verificationService->verify($analyticsSite);
        } catch (\Throwable $exception) {
            Log::error('analytics.verify.unhandled_exception', array_merge(
                $this->buildVerificationLogContext($request, $site, $analyticsSite),
                ['error' => $this->sanitizeLogError($exception->getMessage()), 'exception_class' => $exception::class]
            ));

            return redirect()
                ->route('app.sites.analytics.show', $site)
                ->with($this->buildVerificationFailureFlash(
                    message: 'Verification failed unexpectedly. Please retry in a moment.',
                    details: $this->sanitizeLogError($exception->getMessage()),
                    action: 'retry_verification'
                ));
        }

        Log::info('analytics.verify.result', array_merge(
            $this->buildVerificationLogContext($request, $site, $analyticsSite),
            [
                'success' => $result->success,
                'result_code' => $result->code,
                'response_status' => $result->responseStatus,
                'retryable' => $result->retryable,
            ]
        ));

        if ($result->success) {
            return redirect()
                ->route('app.sites.analytics.show', $site)
                ->with('status', $result->message);
        }

        $errorMessage = $result->message;
        $errorDetails = null;

        if ($this->isLocalSslVerificationFailure($result->message, (string) ($site->site_url ?? ''))) {
            $errorMessage = 'Local SSL verification failed while checking your domain. Enable PUBLISHLAYER_HTTP_INSECURE_LOCAL for local only, or install a trusted local certificate.';
            $errorDetails = $result->details ?: $result->message;
        }

        return redirect()
            ->route('app.sites.analytics.show', $site)
            ->with($this->buildVerificationFailureFlash(
                message: $errorMessage,
                details: $errorDetails ?? $result->details,
                action: $result->action,
                retryable: $result->retryable
            ));
    }

    public function regenerateToken(Request $request, ClientSite $site): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeSiteAccess($site, $user);

        $analyticsSite = $site->analyticsSite;

        if (! $analyticsSite) {
            return redirect()
                ->route('app.sites.analytics.show', $site)
                ->with('error', 'Please enable analytics first.');
        }

        $analyticsSite->verification_token = AnalyticsSite::generateVerificationToken();
        $analyticsSite->verified_at = null;
        $analyticsSite->save();

        return redirect()
            ->route('app.sites.analytics.show', $site)
            ->with('status', 'Verification token regenerated. Please re-verify your domain.');
    }

    private function authorizeSiteAccess(ClientSite $site, $user): void
    {
        $organization = $user->organization;

        if (! $organization) {
            Log::warning('analytics.verify.organization_missing', [
                'site_id' => (string) $site->id,
                'user_id' => $user?->id,
            ]);
            abort(403, 'No organization');
        }

        $belongsToOrg = $site->workspace?->organization_id === $organization->id;

        if (! $belongsToOrg) {
            Log::warning('analytics.verify.organization_mismatch', [
                'site_id' => (string) $site->id,
                'site_workspace_id' => (string) ($site->workspace?->id ?? ''),
                'site_organization_id' => (string) ($site->workspace?->organization_id ?? ''),
                'user_id' => $user?->id,
                'user_organization_id' => (string) $organization->id,
            ]);
            abort(403, 'Site does not belong to your organization');
        }
    }

    private function getTrackingBaseUrl(): string
    {
        $configuredUrl = trim((string) config('publishlayer.tracking_url', ''));

        if ($configuredUrl !== '') {
            return rtrim($configuredUrl, '/');
        }

        return $this->getTrackHost();
    }

    private function getTrackHost(): string
    {
        $baseDomain = config('domains.base', 'argusly.local');
        $scheme = request()->secure() ? 'https' : 'http';

        return rtrim("{$scheme}://track.{$baseDomain}", '/');
    }

    private function buildTrackingSnippet(?AnalyticsSite $analyticsSite, string $trackingBaseUrl, string $scriptVersion): ?string
    {
        if (! $analyticsSite) {
            return null;
        }

        $scriptSrc = rtrim($trackingBaseUrl, '/') . '/pl.js?v=' . rawurlencode($scriptVersion);
        $siteKey = $analyticsSite->public_key;
        $engagedAfterSeconds = (int) config('analytics.tracking.engaged_after_seconds', 10);
        $readThroughScrollPercent = (int) config('analytics.tracking.read_through_scroll_percent', 75);
        $readThroughFallbackSeconds = (int) config('analytics.tracking.read_through_fallback_seconds', 20);

        return implode(PHP_EOL, [
            '<script>',
            '  window.PublishLayer = window.PublishLayer || {};',
            '  window.PublishLayer.siteKey = "' . $siteKey . '";',
            '  window.PublishLayer.engagedAfterSeconds = ' . $engagedAfterSeconds . ';',
            '  window.PublishLayer.readThroughScrollPercent = ' . $readThroughScrollPercent . ';',
            '  window.PublishLayer.readThroughFallbackSeconds = ' . $readThroughFallbackSeconds . ';',
            '</script>',
            '<script async src="' . $scriptSrc . '"></script>',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(?AnalyticsSite $analyticsSite, string $scope): array
    {
        return $this->siteAnalyticsService->getQuickStats($analyticsSite, $scope);
    }

    private function isLocalSslVerificationFailure(string $message, string $siteUrl): bool
    {
        if (! app()->environment(['local', 'development', 'dev'])) {
            return false;
        }

        if (! str_contains($message, 'cURL error 60')) {
            return false;
        }

        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        return $host === 'argusly.local'
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || str_ends_with($host, '.local');
    }

    /**
     * @return array<string,mixed>
     */
    private function buildVerificationLogContext(Request $request, ClientSite $site, ?AnalyticsSite $analyticsSite): array
    {
        return [
            'route' => 'app.sites.analytics.verify',
            'request_method' => $request->method(),
            'site_id' => (string) $site->id,
            'site_url' => (string) ($site->site_url ?? ''),
            'workspace_id' => (string) ($site->workspace?->id ?? ''),
            'site_organization_id' => (string) ($site->workspace?->organization_id ?? ''),
            'user_id' => $request->user()?->id,
            'user_organization_id' => $request->user()?->organization_id,
            'analytics_site_id' => $analyticsSite?->id,
            'analytics_enabled' => $analyticsSite?->is_enabled,
            'analytics_verified_at' => $analyticsSite?->verified_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildVerificationFailureFlash(
        string $message,
        ?string $details = null,
        ?string $action = null,
        bool $retryable = false,
    ): array {
        return [
            'error' => $message,
            'error_details' => $details,
            'analytics_error_action' => $action ?: ($retryable ? 'retry_verification' : null),
        ];
    }

    private function sanitizeLogError(string $message): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? $message), 0, 180);
    }
}
