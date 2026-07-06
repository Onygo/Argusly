<?php

namespace App\Services\PageIntelligence\Reports;

use App\Models\PageIntelligenceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PageIntelligenceReportArtifactGenerator
{
    public function generate(PageIntelligenceReport $report): PageIntelligenceReport
    {
        $report = $report->fresh() ?? $report;
        $sourceChecksum = $this->snapshotChecksum($report);
        $path = $this->storagePath($report);
        $disk = Storage::disk('local');

        if (
            $report->artifact_status === PageIntelligenceReport::ARTIFACT_STATUS_READY
            && $report->artifact_storage_path === $path
            && $report->artifact_source_checksum === $sourceChecksum
            && $disk->exists($path)
            && $report->artifact_checksum === hash('sha256', $disk->get($path))
        ) {
            return $report;
        }

        $report->forceFill([
            'artifact_type' => PageIntelligenceReport::ARTIFACT_TYPE_PDF,
            'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_GENERATING,
            'artifact_storage_path' => $path,
            'artifact_checksum' => null,
            'artifact_source_checksum' => $sourceChecksum,
            'artifact_failed_at' => null,
            'artifact_error' => null,
            'artifact_attempt_count' => ((int) $report->artifact_attempt_count) + 1,
        ])->save();

        try {
            $bytes = $this->renderPdf($report);
            $artifactChecksum = hash('sha256', $bytes);
            $disk->put($path, $bytes);

            $report->forceFill([
                'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_READY,
                'artifact_storage_path' => $path,
                'artifact_generated_at' => now(),
                'artifact_checksum' => $artifactChecksum,
                'artifact_source_checksum' => $sourceChecksum,
                'artifact_failed_at' => null,
                'artifact_error' => null,
            ])->save();

            return $report->fresh() ?? $report;
        } catch (Throwable $exception) {
            $report->forceFill([
                'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_FAILED,
                'artifact_storage_path' => $path,
                'artifact_checksum' => null,
                'artifact_source_checksum' => $sourceChecksum,
                'artifact_failed_at' => now(),
                'artifact_error' => mb_substr($exception->getMessage(), 0, 4000),
            ])->save();

            throw $exception;
        }
    }

    public function snapshotChecksum(PageIntelligenceReport $report): string
    {
        return hash('sha256', json_encode([
            'report_id' => $report->id,
            'identity_hash' => $report->identity_hash,
            'snapshot_version' => $report->snapshot_version,
            'template_version' => $report->template_version,
            'payload' => $report->payload_json,
            'provenance' => $report->provenance_json,
        ], JSON_THROW_ON_ERROR));
    }

    public function storagePath(PageIntelligenceReport $report): string
    {
        return sprintf(
            'page-intelligence/reports/%s/%s/snapshot-%d.pdf',
            $report->workspace_id,
            $report->id,
            (int) $report->snapshot_version
        );
    }

    private function renderPdf(PageIntelligenceReport $report): string
    {
        Pdf::setOption([
            'isHtml5ParserEnabled' => true,
            'dpi' => 96,
            'defaultFont' => 'Arial',
        ]);

        $pdf = Pdf::loadView('app.page-intelligence.reports.export', [
            'report' => $report,
            'payload' => $report->payload_json ?? [],
        ]);
        $pdf->setPaper('a4');

        return $pdf->output();
    }
}
