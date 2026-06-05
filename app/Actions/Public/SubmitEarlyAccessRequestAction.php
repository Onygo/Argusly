<?php

namespace App\Actions\Public;

use App\Models\ContactSubmission;
use App\Services\ContactSubmissionMailer;
use App\Services\EarlyAccessSignupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubmitEarlyAccessRequestAction
{
    public function __construct(
        private readonly ContactSubmissionMailer $mailer,
        private readonly EarlyAccessSignupService $signups,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function execute(array $payload, Request $request): ContactSubmission
    {
        $intent = strtolower(trim((string) ($payload['intent'] ?? 'early_access')));
        $intent = in_array($intent, ['early_access', 'demo'], true) ? $intent : 'early_access';

        $subject = $intent === 'demo' ? 'Demo request' : 'Early access request';
        $topic = $intent === 'demo' ? 'Book a demo' : 'Request early access';

        $submission = DB::transaction(function () use ($payload, $request, $subject, $topic): ContactSubmission {
            $this->signups->createFromPublicSubmission($payload, $request);

            return ContactSubmission::query()->create([
                'name' => trim((string) $payload['full_name']),
                'email' => strtolower(trim((string) $payload['work_email'])),
                'company' => trim((string) ($payload['company'] ?? '')) ?: null,
                'subject' => $subject,
                'message' => trim((string) ($payload['message'] ?? '')),
                'topic' => $topic,
                'source_page' => trim((string) $request->headers->get('referer', $request->path())) ?: null,
                'cta_label' => $topic,
                'url' => trim((string) ($payload['website'] ?? '')) ?: null,
                'ip_address' => (string) $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        });

        $this->mailer->send($submission);

        return $submission;
    }
}
