<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SignalStatus;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignalFeedItem extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'signal_source_id',
        'external_id',
        'url',
        'url_hash',
        'title',
        'summary',
        'body',
        'author',
        'published_at',
        'fetched_at',
        'language',
        'raw_payload',
        'content_hash',
        'processing_status',
        'processing_error',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'published_at' => 'datetime',
        'fetched_at' => 'datetime',
        'raw_payload' => 'array',
        'processing_status' => SignalStatus::class,
        'deleted_at' => 'datetime',
    ];

    public function signalSource(): BelongsTo
    {
        return $this->belongsTo(SignalSource::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(SignalMention::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignalEvent::class);
    }
}
