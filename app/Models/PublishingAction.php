<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'content_asset_id',
    'publishing_channel_id',
    'language',
    'locale',
    'action',
    'status',
    'scheduled_at',
    'published_at',
    'external_id',
    'external_url',
    'request_payload',
    'response_payload',
    'error_message',
    'created_by',
])]
class PublishingAction extends Model
{
    use HasFactory;

    public const ACTIONS = [
        'publish',
        'update',
        'unpublish',
        'schedule',
    ];

    public const STATUSES = [
        'queued',
        'processing',
        'completed',
        'failed',
        'cancelled',
    ];

    protected static function booted(): void
    {
        static::creating(function (PublishingAction $action): void {
            $action->uuid ??= (string) Str::uuid();
            $action->status ??= 'queued';
        });

        static::saving(function (PublishingAction $action): void {
            $contentAsset = ContentAsset::query()->find($action->content_asset_id);

            if (! $contentAsset || $contentAsset->account_id !== $action->account_id || $contentAsset->brand_id !== $action->brand_id) {
                throw new InvalidArgumentException('Publishing action content asset must belong to the same account and brand.');
            }

            if ($action->publishing_channel_id === null) {
                return;
            }

            $channel = PublishingChannel::query()->find($action->publishing_channel_id);

            if (! $channel || $channel->account_id !== $action->account_id || $channel->brand_id !== $action->brand_id) {
                throw new InvalidArgumentException('Publishing action channel must belong to the same account and brand.');
            }
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<ContentAsset, $this>
     */
    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class);
    }

    /**
     * @return BelongsTo<PublishingChannel, $this>
     */
    public function publishingChannel(): BelongsTo
    {
        return $this->belongsTo(PublishingChannel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }
}
