<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentSource extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;

    // Generation status constants
    public const GENERATION_STATUS_PENDING = 'pending';

    public const GENERATION_STATUS_QUEUED = 'queued';

    public const GENERATION_STATUS_RUNNING = 'running';

    public const GENERATION_STATUS_COMPLETED = 'completed';

    public const GENERATION_STATUS_FAILED = 'failed';

    protected $fillable = [
        'workspace_id',
        'type',
        'source_url',
        'final_url',
        'source_domain',
        'source_title',
        'source_language',
        'extraction_status',
        'generation_status',
        'generation_progress_step',
        'generation_failure_code',
        'generation_failure_message',
        'generation_diagnostics_json',
        'generation_started_at',
        'generation_completed_at',
        'generation_output_mode',
        'generation_locale',
        'generation_intent',
        'generation_idempotency_key',
        'result_content_id',
        'result_brief_id',
        'fetched_at',
        'metadata_json',
        'extracted_text',
        'extracted_outline_json',
        'analysis_json',
        'generated_payload_json',
        'created_by_user_id',
    ];

    protected $casts = [
        'fetched_at' => 'datetime',
        'generation_started_at' => 'datetime',
        'generation_completed_at' => 'datetime',
        'metadata_json' => 'array',
        'extracted_outline_json' => 'array',
        'analysis_json' => 'array',
        'generated_payload_json' => 'array',
        'generation_diagnostics_json' => 'array',
    ];

    public function isGenerationPending(): bool
    {
        return in_array((string) $this->generation_status, [
            self::GENERATION_STATUS_PENDING,
            self::GENERATION_STATUS_QUEUED,
            self::GENERATION_STATUS_RUNNING,
        ], true);
    }

    public function isGenerationCompleted(): bool
    {
        return (string) $this->generation_status === self::GENERATION_STATUS_COMPLETED;
    }

    public function isGenerationFailed(): bool
    {
        return (string) $this->generation_status === self::GENERATION_STATUS_FAILED;
    }

    public function canStartGeneration(): bool
    {
        return in_array((string) $this->generation_status, [
            self::GENERATION_STATUS_PENDING,
            self::GENERATION_STATUS_FAILED,
        ], true);
    }

    public function markGenerationQueued(
        string $outputMode,
        ?string $locale = null,
        ?string $intent = null,
        ?string $idempotencyKey = null
    ): void
    {
        $this->update([
            'generation_status' => self::GENERATION_STATUS_QUEUED,
            'generation_progress_step' => 'queued',
            'generation_output_mode' => $outputMode,
            'generation_locale' => $locale,
            'generation_intent' => $intent,
            'generation_idempotency_key' => $idempotencyKey,
            'generation_failure_code' => null,
            'generation_failure_message' => null,
            'generation_diagnostics_json' => null,
            'generation_started_at' => null,
            'generation_completed_at' => null,
        ]);
    }

    public function markGenerationRunning(?string $step = null): void
    {
        $this->update([
            'generation_status' => self::GENERATION_STATUS_RUNNING,
            'generation_progress_step' => $step ?: 'running',
            'generation_started_at' => now(),
        ]);
    }

    public function markGenerationProgress(string $step): void
    {
        $this->update([
            'generation_status' => self::GENERATION_STATUS_RUNNING,
            'generation_progress_step' => $step,
        ]);
    }

    public function markGenerationCompleted(
        array $analysisJson,
        array $generatedPayload,
        ?string $contentId = null,
        ?string $briefId = null
    ): void
    {
        $this->update([
            'generation_status' => self::GENERATION_STATUS_COMPLETED,
            'generation_progress_step' => 'completed',
            'generation_completed_at' => now(),
            'extraction_status' => 'generated',
            'analysis_json' => $analysisJson,
            'generated_payload_json' => $generatedPayload,
            'result_content_id' => $contentId,
            'result_brief_id' => $briefId,
        ]);
    }

    public function markGenerationFailed(string $code, string $message, ?array $diagnostics = null): void
    {
        $this->update([
            'generation_status' => self::GENERATION_STATUS_FAILED,
            'generation_progress_step' => 'failed',
            'generation_completed_at' => now(),
            'generation_failure_code' => $code,
            'generation_failure_message' => $message,
            'generation_diagnostics_json' => $diagnostics,
        ]);
    }

    public function getGenerationProgressLabel(): string
    {
        return match ((string) $this->generation_status) {
            self::GENERATION_STATUS_PENDING => 'Waiting to start',
            self::GENERATION_STATUS_QUEUED => 'Queued for generation',
            self::GENERATION_STATUS_RUNNING => match ((string) $this->generation_progress_step) {
                'fetching_source' => 'Fetching source',
                'extracting_source' => 'Extracting source content',
                'building_workspace_context' => 'Building workspace context',
                'analyzing_source' => 'Analyzing source content',
                'generating_chain' => 'Generating chain proposal',
                'generating_brief' => 'Generating brief proposal',
                default => 'Analyzing and generating brief',
            },
            self::GENERATION_STATUS_COMPLETED => 'Generation completed',
            self::GENERATION_STATUS_FAILED => 'Generation failed',
            default => 'Unknown status',
        };
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function briefs()
    {
        return $this->hasMany(Brief::class);
    }
}
