<?php

namespace App\Http\Controllers;

use App\Http\Requests\Public\StoreContactRequest;
use App\Models\ContactSubmission;
use App\Services\ContactSubmissionMailer;
use App\Services\Security\RecaptchaService;
use Illuminate\Http\RedirectResponse;

class PublicContactController extends Controller
{
    public function store(
        StoreContactRequest $request,
        ContactSubmissionMailer $mailer,
        RecaptchaService $recaptcha
    ): RedirectResponse {
        if (! $recaptcha->verify($request->input('recaptcha_token'), 'contact')) {
            return back()
                ->withErrors([
                    'recaptcha_token' => (string) __('public.page.contact_form.recaptcha_failed'),
                ])
                ->withInput($request->except('recaptcha_token'));
        }

        $data = $request->validated();

        unset($data['recaptcha_token']);

        $submission = ContactSubmission::query()->create([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'company' => ($data['company'] ?? '') !== '' ? (string) $data['company'] : null,
            'subject' => ($data['subject'] ?? '') !== '' ? (string) $data['subject'] : null,
            'message' => (string) $data['message'],
            'topic' => ($data['topic'] ?? '') !== '' ? (string) $data['topic'] : null,
            'source_page' => ($data['source_page'] ?? '') !== '' ? (string) $data['source_page'] : null,
            'cta_label' => ($data['cta_label'] ?? '') !== '' ? (string) $data['cta_label'] : null,
            'url' => ($data['url'] ?? '') !== '' ? (string) $data['url'] : null,
            'ip_address' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        $mailer->send($submission);

        return back()->with('contact_status', (string) __('public.contact.success'));
    }
}
