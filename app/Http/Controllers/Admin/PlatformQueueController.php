<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\QueueHealthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

class PlatformQueueController extends Controller
{
    public function index(QueueHealthService $queues): View
    {
        return view('admin.platform.queues', [
            'queue' => $queues->snapshot(),
        ]);
    }

    public function retry(int $failedJob, QueueHealthService $queues): RedirectResponse
    {
        abort_unless($queues->retryFailedJob($failedJob), 404);

        Artisan::call('queue:retry', ['id' => [$failedJob]]);

        return back()->with('status', "Failed job {$failedJob} queued for retry.");
    }
}
