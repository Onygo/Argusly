<?php

namespace App\Http\Controllers;

use App\Http\Requests\Public\StoreContactRequest;
use App\Models\ContactSubmission;
use App\Services\ContactSubmissionMailer;
use App\Services\EarlyAccessSignupService;
use App\Services\Security\RecaptchaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PublicContactController extends Controller
{
    public function store(
        StoreContactRequest $request,
        ContactSubmissionMailer $mailer,
        EarlyAccessSignupService $earlyAccessSignups,
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

        $submission = DB::transaction(function () use ($data, $request, $earlyAccessSignups): ContactSubmission {
            $submission = ContactSubmission::query()->create([
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'company' => ($data['company'] ?? '') !== '' ? (string) $data['company'] : null,
                'website' => ($data['website'] ?? '') !== '' ? (string) $data['website'] : null,
                'market' => ($data['market'] ?? '') !== '' ? (string) $data['market'] : null,
                'competitors' => ($data['competitors'] ?? '') !== '' ? (string) $data['competitors'] : null,
                'growth_goal' => ($data['growth_goal'] ?? '') !== '' ? (string) $data['growth_goal'] : null,
                'interest_area' => ($data['interest_area'] ?? '') !== '' ? (string) $data['interest_area'] : null,
                'subject' => ($data['subject'] ?? '') !== '' ? (string) $data['subject'] : null,
                'message' => (string) $data['message'],
                'topic' => ($data['topic'] ?? '') !== '' ? (string) $data['topic'] : null,
                'source_page' => ($data['source_page'] ?? '') !== '' ? (string) $data['source_page'] : null,
                'cta_label' => ($data['cta_label'] ?? '') !== '' ? (string) $data['cta_label'] : null,
                'url' => ($data['url'] ?? '') !== '' ? (string) $data['url'] : null,
                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            if ($this->isPilotApplication($data)) {
                $earlyAccessSignups->createFromPublicSubmission([
                    'full_name' => (string) $data['name'],
                    'email' => (string) $data['email'],
                    'company' => (string) ($data['company'] ?? ''),
                    'website' => (string) ($data['website'] ?? ''),
                    'message' => (string) $data['message'],
                    'source' => 'pilot_contact',
                    'notes' => $this->pilotApplicationNotes($data),
                ], $request);
            }

            return $submission;
        });

        $mailer->send($submission);

        return back()->with('contact_status', (string) __('public.contact.success'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function isPilotApplication(array $data): bool
    {
        return strtolower(trim((string) ($data['topic'] ?? ''))) === 'pilot';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pilotApplicationNotes(array $data): ?string
    {
        $notes = array_filter([
            'Subject: ' . trim((string) ($data['subject'] ?? '')),
            'Market: ' . trim((string) ($data['market'] ?? '')),
            'Competitors: ' . trim((string) ($data['competitors'] ?? '')),
            'Growth goal: ' . trim((string) ($data['growth_goal'] ?? '')),
            'Interest area: ' . trim((string) ($data['interest_area'] ?? '')),
            'Source page: ' . trim((string) ($data['source_page'] ?? '')),
            'CTA: ' . trim((string) ($data['cta_label'] ?? '')),
        ], fn (string $note): bool => ! str_ends_with($note, ': '));

        return $notes === [] ? null : implode("\n", $notes);
    }
}
