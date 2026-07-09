<?php

namespace App\Models;

use App\Support\MarketingMetadataRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingAttribution extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'marketing_observation_id',
        'attribution_type',
        'attributed_type',
        'attributed_id',
        'attribution_key',
        'attribution_value',
        'weight',
        'confidence_score',
        'model_key',
        'source_metadata_json',
        'metadata_json',
    ];

    protected $casts = [
        'weight' => 'decimal:6',
        'confidence_score' => 'decimal:4',
        'source_metadata_json' => 'array',
        'metadata_json' => 'array',
    ];

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(MarketingObservation::class, 'marketing_observation_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function setSourceMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['source_metadata_json'] = $this->encodedRedactedMetadata($value);
    }

    public function setMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['metadata_json'] = $this->encodedRedactedMetadata($value);
    }

    private function encodedRedactedMetadata(?array $value): ?string
    {
        return $value === null
            ? null
            : json_encode(MarketingMetadataRedactor::redact($value));
    }
}
