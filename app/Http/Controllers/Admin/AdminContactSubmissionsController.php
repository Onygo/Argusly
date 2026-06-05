<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use App\Services\ContactSubmissionMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminContactSubmissionsController extends Controller
{
    public function index(): View
    {
        $submissions = ContactSubmission::query()
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('admin.contact-submissions.index', [
            'submissions' => $submissions,
        ]);
    }

    public function resend(Request $request, ContactSubmission $submission, ContactSubmissionMailer $mailer): RedirectResponse
    {
        $sent = $mailer->send($submission);

        if ($sent) {
            return back()->with('status', 'Contact email resent successfully.');
        }

        return back()->withErrors(['contact' => 'Resend failed: ' . (string) ($submission->fresh()->mail_error ?: 'unknown error')]);
    }
}
