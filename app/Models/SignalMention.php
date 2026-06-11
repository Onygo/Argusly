<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignalMention extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const TYPE_BRAND = 'brand';
    public const TYPE_COMPETITOR = 'competitor';
    public const TYPE_PRODUCT = 'product';
    public const TYPE_PERSON = 'person';
    public const TYPE_TOPIC = 'topic';
    public const TYPE_SOURCE = 'source';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_UNKNOWN = 'unknown';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'signal_feed_item_id',
        'signal_entity_id',
        'source_type',
        'source_ref_type',
        'source_ref_id',
        'mention_type',
        'entity_type',
        'entity_name',
        'entity_key',
        'canonical_entity_id',
        'url',
        'url_hash',
        'context',
        'sentiment_label',
        'sentiment_score',
        'position_score',
        'confidence_score',
        'observed_at',
        'metadata',
        'dedupe_hash',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'sentiment_score' => 'float',
        'position_score' => 'float',
        'confidence_score' => 'float',
        'observed_at' => 'datetime',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function signalFeedItem(): BelongsTo
    {
        return $this->belongsTo(SignalFeedItem::class);
    }

    public function signalEntity(): BelongsTo
    {
        return $this->belongsTo(SignalEntity::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignalEvent::class);
    }

    public function scopeMentionType(Builder $query, string $type): Builder
    {
        return $query->where('mention_type', $type);
    }
}
