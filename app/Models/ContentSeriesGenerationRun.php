<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentSeriesGenerationRun extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'series_id',
        'organization_id',
        'requested_by',
        'credit_ledger_entry_id',
        'workspace_credit_transaction_id',
        'total_articles',
        'completed_articles',
        'failed_articles',
        'status',
        'started_at',
        'finished_at',
        'last_error',
        'meta',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'requested_by' => 'integer',
        'total_articles' => 'integer',
        'completed_articles' => 'integer',
        'failed_articles' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    public function series()
    {
        return $this->belongsTo(ContentSeries::class, 'series_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function articles()
    {
        return $this->hasMany(ContentSeriesGenerationRunArticle::class, 'run_id');
    }

    public function creditLedgerEntry()
    {
        return $this->belongsTo(CreditLedgerEntry::class, 'credit_ledger_entry_id');
    }

    public function workspaceCreditTransaction()
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'workspace_credit_transaction_id');
    }
}
