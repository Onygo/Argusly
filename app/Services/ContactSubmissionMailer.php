<?php

namespace App\Services;

use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use Illuminate\Support\Facades\Mail;

class ContactSubmissionMailer
{
    public function send(ContactSubmission $submission): bool
    {
        $recipient = (string) config('argusly.contact.recipient_email', config('mail.from.address'));
        $preferredMailer = (string) config('argusly.contact.mailer', 'mailgun');
        $fallbackMailer = (string) config('mail.default', 'log');
        $mailers = array_values(array_unique(array_filter([$preferredMailer, $fallbackMailer])));
        $errors = [];

        foreach ($mailers as $mailer) {
            try {
                Mail::mailer($mailer)
                    ->to($recipient)
                    ->send(new ContactSubmissionReceived($submission));

                $submission->mail_sent_at = now();
                $submission->mail_error = null;
                $submission->save();

                return true;
            } catch (\Throwable $exception) {
                $errors[] = sprintf('[%s] %s', $mailer, $exception->getMessage());
            }
        }

        $submission->mail_error = mb_substr(implode(' | ', $errors), 0, 2000);
        $submission->save();

        return false;
    }
}

