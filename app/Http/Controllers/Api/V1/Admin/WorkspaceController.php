<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function update(Request $request, Workspace $workspace, AuditLogService $auditLogs): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
        ]);

        $before = [
            'display_name' => (string) $workspace->display_name,
        ];

        $workspace->display_name = trim((string) $data['display_name']);
        $workspace->save();

        $after = [
            'display_name' => (string) $workspace->display_name,
        ];

        $auditLogs->log(
            actor: null,
            subject: $workspace,
            action: 'workspace.display_name.updated',
            before: $before,
            after: $after,
            request: $request
        );

        return response()->json([
            'id' => (string) $workspace->id,
            'display_name' => (string) $workspace->display_name,
        ]);
    }
}

