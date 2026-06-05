<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImagePreset extends Model
{
    use BelongsToOrganization;
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'instructions',
        'is_default',
        'created_by',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to fetch only the default preset.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to fetch presets for a specific organization.
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->withoutGlobalScopes()->where('organization_id', $organizationId);
    }

    // =========================================================================
    // Static Helpers
    // =========================================================================

    /**
     * Get the default preset for an organization.
     */
    public static function getDefaultForOrganization(int $organizationId): ?self
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Get all presets for an organization ordered by name.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function getAllForOrganization(int $organizationId)
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Check if this preset is the default.
     */
    public function isDefault(): bool
    {
        return (bool) $this->is_default;
    }

    /**
     * Get instructions truncated for display.
     */
    public function getInstructionsPreview(int $maxLength = 100): string
    {
        $instructions = trim((string) $this->instructions);

        if (mb_strlen($instructions) <= $maxLength) {
            return $instructions;
        }

        return mb_substr($instructions, 0, $maxLength) . '...';
    }
}
