<?php

namespace App\Models;

use App\Enums\ContentDestinationStatus;
use App\Enums\EmailMarketingProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

class EmailMarketingConnection extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
        'provider',
        'status',
        'config',
        'credentials',
        'created_by',
        'last_used_at',
    ];

    protected $casts = [
        'provider' => EmailMarketingProvider::class,
        'status' => ContentDestinationStatus::class,
        'config' => 'array',
        'last_used_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exports(): HasMany
    {
        return $this->hasMany(EmailCampaignExport::class);
    }

    public function isActive(): bool
    {
        return $this->status === ContentDestinationStatus::ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        $encrypted = trim((string) $this->getAttribute('credentials'));
        if ($encrypted === '') {
            return [];
        }

        $value = Crypt::decrypt($encrypted);

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function setCredentials(array $credentials): void
    {
        $this->setAttribute('credentials', Crypt::encrypt($credentials));
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }

    public function secretValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials(), $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitizedConfig(): array
    {
        return [
            'config' => $this->config ?? [],
            'credentials' => [
                'has_api_key' => trim((string) $this->secretValue('api_key', '')) !== '',
            ],
        ];
    }
}
