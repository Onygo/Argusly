<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Draft;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminDraftsController extends Controller
{
    public function index(): View
    {
        $drafts = Draft::query()
            ->with('clientSite.workspace.organization', 'brief')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.drafts.index', [
            'drafts' => $drafts,
        ]);
    }

    public function destroy(Draft $draft): RedirectResponse
    {
        $title = (string) $draft->title;
        $draft->delete();

        return back()->with('status', 'Draft deleted: ' . $title);
    }
}
