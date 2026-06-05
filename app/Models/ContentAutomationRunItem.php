<?php

namespace App\Models;

use App\Support\TitleSanitizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ContentAutomationRunItem extends Model
{
    use HasUuids;

    public const TYPE_SOURCE = 'source';
    public const TYPE_TRANSLATION = 'translation';

    public const STATUS_PLANNED = 'planned';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const TRANSLATION_STATUS_NOT_REQUIRED = 'not_required';

    protected $fillable = [
        'automation_run_id',
        'automation_id',
        'source_run_item_id',
        'chain_index',
        'item_type',
        'status',
        'failure_stage',
        'last_error_code',
        'last_error_message',
        'content_id',
        'content_family_id',
        'draft_id',
        'brief_id',
        'client_site_id',
        'locale',
        'source_locale',
        'is_source_locale',
        'title',
        'generation_status',
        'translation_status',
        'delivery_status',
        'publication_status',
        'provider',
        'model',
        'prompt_hash',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'chain_index' => 'integer',
        'is_source_locale' => 'boolean',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function setTitleAttribute(mixed $value): void
    {
        $result = TitleSanitizer::normalizeWithMetadata($value);
        $this->attributes['title'] = $result['title'];

        if ($result['was_shortened']) {
            Log::notice('content_automation.run_item_title_shortened', [
                'run_item_id' => $this->getKey(),
                'automation_run_id' => (string) ($this->automation_run_id ?? ''),
                'automation_id' => (string) ($this->automation_id ?? ''),
                'original_length' => $result['original_length'],
                'persisted_length' => $result['persisted_length'],
                'max_length' => $result['max_length'],
            ]);
        }
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentAutomationRun::class, 'automation_run_id');
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(ContentAutomation::class, 'automation_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_run_item_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }
}
