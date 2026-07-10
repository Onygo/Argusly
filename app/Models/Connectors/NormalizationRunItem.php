<?php

namespace App\Models\Connectors;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizationRunItem extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'connector_normalization_run_items';

    protected $fillable = [
        'connector_normalization_run_id',
        'connector_raw_record_id',
        'entity_type',
        'status',
        'records_written',
        'error_message',
        'metadata_json',
    ];

    protected $casts = [
        'records_written' => 'integer',
        'metadata_json' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(NormalizationRun::class, 'connector_normalization_run_id');
    }

    public function rawRecord(): BelongsTo
    {
        return $this->belongsTo(ConnectorRawRecord::class, 'connector_raw_record_id');
    }
}
