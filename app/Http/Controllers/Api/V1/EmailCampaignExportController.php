<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EmailCampaignExportResource;
use App\Enums\EmailMarketingExportStatus;
use App\Models\CampaignContent;
use App\Models\EmailCampaignExport;
use App\Models\EmailMarketingConnection;
use App\Services\EmailMarketing\EmailCampaignExportService;
use App\Services\EmailMarketing\EmailMarketingProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailCampaignExportController extends Controller
{
    use RespondsWithApi;

    public function store(Request $request, CampaignContent $campaignContent, EmailCampaignExportService $exports): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace, 403);

        $campaignContent->loadMissing('campaign');
        abort_unless((string) $campaignContent->campaign?->workspace_id === (string) $workspace->id, 404);

        $validated = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'subject' => ['nullable', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'cta_label' => ['nullable', 'string', 'max:120'],
            'cta_url' => ['nullable', 'url', 'max:2048'],
            'locale' => ['nullable', 'string', 'max:10'],
            'template_id' => ['nullable', 'string', 'max:255'],
            'audience_id' => ['nullable', 'string', 'max:255'],
        ]);

        $connection = EmailMarketingConnection::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($validated['connection_id']);

        try {
            $export = $exports->export($campaignContent, $connection, collect($validated)->except('connection_id')->all());
        } catch (EmailMarketingProviderException $exception) {
            return $this->error($exception->getMessage(), code: 'EMAIL_MARKETING_EXPORT_FAILED', status: 422);
        }

        return $this->success((new EmailCampaignExportResource($export))->resolve(), status: 201);
    }

    public function show(Request $request, EmailCampaignExport $emailCampaignExport): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace && (string) $emailCampaignExport->workspace_id === (string) $workspace->id, 404);

        return $this->success((new EmailCampaignExportResource($emailCampaignExport->load('metrics')))->resolve());
    }

    public function metrics(Request $request, EmailCampaignExport $emailCampaignExport): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($workspace && (string) $emailCampaignExport->workspace_id === (string) $workspace->id, 404);

        $validated = $request->validate([
            'sent' => ['nullable', 'integer', 'min:0'],
            'delivered' => ['nullable', 'integer', 'min:0'],
            'opens' => ['nullable', 'integer', 'min:0'],
            'unique_opens' => ['nullable', 'integer', 'min:0'],
            'clicks' => ['nullable', 'integer', 'min:0'],
            'unique_clicks' => ['nullable', 'integer', 'min:0'],
            'bounces' => ['nullable', 'integer', 'min:0'],
            'unsubscribes' => ['nullable', 'integer', 'min:0'],
            'conversions' => ['nullable', 'integer', 'min:0'],
            'revenue' => ['nullable', 'numeric', 'min:0'],
            'raw' => ['nullable', 'array'],
            'measured_at' => ['nullable', 'date'],
        ]);

        $emailCampaignExport->metrics()->updateOrCreate(
            ['email_campaign_export_id' => (string) $emailCampaignExport->id],
            array_merge([
                'sent' => 0,
                'delivered' => 0,
                'opens' => 0,
                'unique_opens' => 0,
                'clicks' => 0,
                'unique_clicks' => 0,
                'bounces' => 0,
                'unsubscribes' => 0,
                'conversions' => 0,
                'revenue' => 0,
                'raw' => [],
                'measured_at' => now(),
            ], $validated)
        );

        $emailCampaignExport->forceFill([
            'status' => EmailMarketingExportStatus::SYNCED,
            'last_synced_at' => now(),
        ])->save();

        return $this->success((new EmailCampaignExportResource($emailCampaignExport->fresh()->load('metrics')))->resolve());
    }
}
