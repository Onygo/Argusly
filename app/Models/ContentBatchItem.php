<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentBatchItem extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'batch_id',
        'subkeyword',
        'angle',
        'intent',
        'status',
        'brief_id',
        'draft_id',
        'sort_order',
        'error_message',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function batch()
    {
        return $this->belongsTo(ContentBatch::class, 'batch_id');
    }

    public function brief()
    {
        return $this->belongsTo(Brief::class);
    }

    public function draft()
    {
        return $this->belongsTo(Draft::class);
    }
}

