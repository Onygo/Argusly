<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SignalEntityType;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignalEntity extends Model
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
        'entity_type',
        'entity_key',
        'entity_name',
        'first_seen_at',
        'last_seen_at',
        'mention_count',
        'signal_count',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'entity_type' => SignalEntityType::class,
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'mention_count' => 'integer',
        'signal_count' => 'integer',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function mentions(): HasMany
    {
        return $this->hasMany(SignalMention::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignalEvent::class);
    }
}
