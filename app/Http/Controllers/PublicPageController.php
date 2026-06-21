<?php

namespace App\Http\Controllers;

use App\Support\LocalizedMarketingUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicPageController extends Controller
{
    public function show(Request $request, string $key): View|RedirectResponse
    {
        /** @var array<string, array<string, mixed>> $pages */
        $pages = trans('public.pages');
        abort_unless(isset($pages[$key]), 404);

        $payload = $pages[$key];
        $locale = (string) app()->getLocale();
        $marketingRoute = trim((string) $request->route('marketing_route'));

        if ($key === 'company.contact' && $request->isMethod('GET')) {
            $query = $request->query();
            $cleanQuery = array_diff_key($query, array_flip(['topic', 'source', 'cta']));

            if ($cleanQuery !== $query) {
                $url = $marketingRoute !== ''
                    ? LocalizedMarketingUrl::route($marketingRoute, $cleanQuery, $locale)
                    : $request->url() . ($cleanQuery !== [] ? '?' . http_build_query($cleanQuery) : '');

                return redirect()->to($url . '#contact-form', 301);
            }
        }

        $subject = (string) $request->query('subject', '');
        $contactPrefill = $key === 'company.contact' ? $this->contactPrefillForSubject($subject) : [];
        $payload['pageKey'] = $key;
        $payload['topic'] = (string) $request->query('topic', $contactPrefill['topic'] ?? $subject);
        $payload['subject'] = (string) ($contactPrefill['subject'] ?? $subject);
        $payload['source'] = (string) $request->query('source', '');
        $payload['cta'] = (string) $request->query('cta', '');
        $payload['scheduleCallUrl'] = (string) config('argusly.contact.schedule_call_url', '');
        $payload['canonicalUrl'] = $marketingRoute !== '' ? LocalizedMarketingUrl::route($marketingRoute, [], $locale) : request()->url();
        $payload['hreflangUrls'] = $marketingRoute !== '' ? LocalizedMarketingUrl::hreflangsForRoute($marketingRoute) : [];

        return view('public.page', $payload);
    }

    /**
     * @return array{topic: string, subject: string}|array{}
     */
    private function contactPrefillForSubject(string $subject): array
    {
        return match (strtolower(trim($subject))) {
            'pilot', 'pilot-aanvraag', 'pilot aanvraag', 'pilot-application', 'request-a-pilot' => [
                'topic' => 'pilot',
                'subject' => (string) __('public.contact.prefill_subjects.pilot'),
            ],
            'enterprise-pricing' => [
                'topic' => 'pricing',
                'subject' => (string) __('public.contact.prefill_subjects.enterprise_pricing'),
            ],
            'walkthrough', 'plan-walkthrough', 'plan-a-walkthrough', 'plan-een-walkthrough' => [
                'topic' => 'demo',
                'subject' => (string) __('public.contact.prefill_subjects.walkthrough'),
            ],
            default => [],
        };
    }

    public function redirectLegacyProduct(Request $request, string $anchor): RedirectResponse
    {
        $query = $request->query();
        $url = LocalizedMarketingUrl::route('public.product.platform', $query, (string) app()->getLocale());

        return redirect()->to($url.'#'.$anchor, 301);
    }
}
