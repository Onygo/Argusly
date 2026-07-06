<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Onygo\ArguslyConnector\ActivityState;
use Onygo\ArguslyConnector\InstalledVersions;

final class ConnectorActivityController extends Controller
{
    public function __invoke(Request $request, ActivityState $activity): JsonResponse
    {
        $configuredToken = $this->configuredToken();
        $incomingToken = trim((string) ($request->input('site_key') ?: $request->input('site_token')));

        if ($incomingToken === '') {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'site_key' => ['The site_key field is required.'],
                ],
            ], 422);
        }

        if ($configuredToken === '' || ! hash_equals($configuredToken, $incomingToken)) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'site_key' => ['The selected site_key is invalid.'],
                ],
            ], 422);
        }

        $configuredWorkspace = trim((string) config('argusly-connector.api.workspace_id', ''));
        $incomingWorkspace = trim((string) ($request->input('workspace_id') ?: $request->input('workspace_uuid')));

        if ($configuredWorkspace !== '' && $incomingWorkspace !== '' && $configuredWorkspace !== $incomingWorkspace) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'workspace_id' => ['The workspace_id does not match this connector.'],
                ],
            ], 422);
        }

        $state = $activity->get();

        return response()->json([
            'ok' => true,
            'last_webhook_received_at' => $state['last_webhook_received_at'] ?? null,
            'last_processed_at' => $state['last_processed_at'] ?? null,
            'last_heartbeat_at' => $state['last_heartbeat_at'] ?? null,
            'recent_events_count_24h' => max(0, (int) ($state['recent_events_count_24h'] ?? 0)),
            'failed_events_count_24h' => max(0, (int) ($state['failed_events_count_24h'] ?? 0)),
            'connector_version' => InstalledVersions::version(),
            'configured' => [
                'api_key' => $configuredToken !== '',
                'workspace_id' => $configuredWorkspace !== '',
                'destination_key' => trim((string) config('argusly-connector.destination.id', '')) !== '',
                'webhooks_enabled' => (bool) config('argusly-connector.webhooks.enabled', true),
            ],
        ]);
    }

    private function configuredToken(): string
    {
        return trim((string) config('argusly-connector.api.token', ''));
    }
}
