<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'auth_type', 'default_scopes', 'supports_refresh_tokens', 'is_active', 'is_system'])]
class Integration extends Model
{
    use HasFactory;

    /**
     * @return HasMany<IntegrationConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_scopes' => 'array',
            'supports_refresh_tokens' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }
}
