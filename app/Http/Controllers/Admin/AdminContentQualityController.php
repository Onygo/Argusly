<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class AdminContentQualityController extends Controller
{
    public function legacy(Request $request): RedirectResponse
    {
        $workspaceId = trim((string) $request->query('workspace_id', ''));

        if ($workspaceId !== '' && Workspace::query()->whereKey($workspaceId)->exists()) {
            return redirect()->route('app.workspaces.content-quality.index', ['workspace' => $workspaceId]);
        }

        abort(404, 'Content Intelligence is available from a scoped customer workspace.');
    }
}
