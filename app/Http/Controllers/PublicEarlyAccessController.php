<?php

namespace App\Http\Controllers;

use App\Actions\Public\SubmitEarlyAccessRequestAction;
use App\Http\Requests\Public\StoreEarlyAccessRequest;
use App\Support\LocalizedMarketingUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicEarlyAccessController extends Controller
{
    public function show(Request $request): View
    {
        $intent = $this->normalizeIntent($request->query('intent'));
        $locale = (string) app()->getLocale();

        return view('public.early-access', [
            'intent' => $intent,
            'metaTitle' => 'Early access | Argusly',
            'metaDescription' => 'Request early access to Argusly or book a guided demo.',
            'canonicalUrl' => LocalizedMarketingUrl::route('public.early-access.show', ['intent' => $intent], $locale),
            'hreflangUrls' => LocalizedMarketingUrl::hreflangsForRoute('public.early-access.show', ['intent' => $intent]),
        ]);
    }

    public function store(StoreEarlyAccessRequest $request, SubmitEarlyAccessRequestAction $submit): RedirectResponse
    {
        $data = $request->validated();
        $intent = $this->normalizeIntent($data['intent'] ?? null);
        $submit->execute($data, $request);

        return redirect()
            ->to(LocalizedMarketingUrl::route('public.early-access.show', ['intent' => $intent], (string) app()->getLocale()))
            ->with('early_access_status', $intent === 'demo'
                ? 'Thanks. We will contact you to schedule a demo.'
                : 'Thanks. Your early access request is received.');
    }

    private function normalizeIntent(mixed $value): string
    {
        $intent = strtolower(trim((string) $value));

        return in_array($intent, ['early_access', 'demo'], true) ? $intent : 'early_access';
    }
}
