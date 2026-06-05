<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brief;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminBriefsController extends Controller
{
    public function index(): View
    {
        $briefs = Brief::query()
            ->with('clientSite.workspace.organization')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.briefs.index', [
            'briefs' => $briefs,
        ]);
    }

    public function destroy(Brief $brief): RedirectResponse
    {
        $title = (string) $brief->title;
        $brief->delete();

        return back()->with('status', 'Brief deleted: ' . $title);
    }
}
