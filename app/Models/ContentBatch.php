<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentBatch extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'user_id',
        'main_keyword',
        'settings_json',
        'status',
        'items_total',
        'items_done',
        'credits_estimated',
        'credits_used',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'items_total' => 'integer',
        'items_done' => 'integer',
        'credits_estimated' => 'integer',
        'credits_used' => 'integer',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(ContentBatchItem::class, 'batch_id')->orderBy('sort_order');
    }
}

