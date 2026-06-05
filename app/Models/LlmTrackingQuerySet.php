<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LlmTrackingQuerySet extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'name',
        'description',
        'locale',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function queries()
    {
        return $this->hasMany(LlmTrackingQuery::class, 'llm_tracking_query_set_id')->orderBy('priority')->orderBy('name');
    }
}
