<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'brand_id',
    'newsletter_id',
    'audience_id',
    'segment_id',
    'email_provider_id',
    'status',
    'total_recipients',
    'sent_count',
    'failed_count',
    'started_at',
    'completed_at',
    'error_message',
])]
class NewsletterSend extends Model
{
    use HasFactory;

    public const STATUSES = ['queued', 'sending', 'sent', 'failed', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(function (NewsletterSend $send): void {
            $send->uuid ??= (string) Str::uuid();
            $send->status ??= 'queued';
        });

        static::saving(function (NewsletterSend $send): void {
            if (! in_array($send->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid newsletter send status [{$send->status}].");
            }

            $send->validateNewsletter();
            $send->validateAudience();
            $send->validateSegment();
            $send->validateEmailProvider();
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    public function emailProvider(): BelongsTo
    {
        return $this->belongsTo(EmailProvider::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NewsletterSendRecipient::class);
    }

    protected function casts(): array
    {
        return [
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    private function validateNewsletter(): void
    {
        $newsletter = Newsletter::query()->find($this->newsletter_id);

        if (! $newsletter || $newsletter->account_id !== $this->account_id || $newsletter->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Newsletter send newsletter must belong to the same account and brand.');
        }
    }

    private function validateAudience(): void
    {
        if ($this->audience_id === null) {
            return;
        }

        $audience = Audience::query()->find($this->audience_id);

        if (! $audience || $audience->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Newsletter send audience must belong to the same account.');
        }

        if ($audience->brand_id !== null && $audience->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Newsletter send audience must belong to the same brand scope.');
        }
    }

    private function validateSegment(): void
    {
        if ($this->segment_id === null) {
            return;
        }

        $segment = Segment::query()->find($this->segment_id);

        if (! $segment || $segment->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Newsletter send segment must belong to the same account.');
        }

        if ($segment->brand_id !== null && $segment->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Newsletter send segment must belong to the same brand scope.');
        }
    }

    private function validateEmailProvider(): void
    {
        if ($this->email_provider_id === null) {
            return;
        }

        $provider = EmailProvider::query()->find($this->email_provider_id);

        if (! $provider || $provider->account_id !== $this->account_id) {
            throw new InvalidArgumentException('Newsletter send email provider must belong to the same account.');
        }

        if ($provider->brand_id !== null && $provider->brand_id !== $this->brand_id) {
            throw new InvalidArgumentException('Newsletter send email provider must belong to the same brand scope.');
        }
    }
}
