<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteCompetitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'name',
        'domain',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (SiteCompetitor $competitor): void {
            $competitor->domain = strtolower(trim((string) $competitor->domain));
            $competitor->name = trim((string) $competitor->name);
            $competitor->notes = trim((string) $competitor->notes) ?: null;
        });
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site()
    {
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function intelligenceRuns()
    {
        return $this->hasMany(CompetitorIntelligenceRun::class, 'site_competitor_id');
    }

    public function contentItems()
    {
        return $this->hasMany(CompetitorContentItem::class, 'site_competitor_id');
    }

    public function topicSignals()
    {
        return $this->hasMany(CompetitorTopicSignal::class, 'site_competitor_id');
    }

    public function contentOpportunities()
    {
        return $this->hasMany(CompetitorContentOpportunity::class, 'site_competitor_id');
    }
}
