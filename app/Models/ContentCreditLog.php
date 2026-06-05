<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentCreditLog extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'content_credit_logs';

    protected $fillable = [
        'content_id',
        'draft_id',
        'credit_ledger_entry_id',
        'workspace_credit_transaction_id',
        'event',
        'credits_used',
        'mode_multiplier',
        'meta',
    ];

    protected $casts = [
        'credits_used' => 'integer',
        'mode_multiplier' => 'float',
        'meta' => 'array',
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function draft()
    {
        return $this->belongsTo(Draft::class);
    }

    public function workspaceCreditTransaction()
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'workspace_credit_transaction_id');
    }
}
