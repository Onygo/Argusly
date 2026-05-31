<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'newsletter_send_id',
    'audience_member_id',
    'email',
    'status',
    'sent_at',
    'error_message',
    'metadata',
])]
class NewsletterSendRecipient extends Model
{
    use HasFactory;

    public const STATUSES = ['queued', 'sending', 'sent', 'failed', 'cancelled'];

    protected static function booted(): void
    {
        static::creating(function (NewsletterSendRecipient $recipient): void {
            $recipient->uuid ??= (string) Str::uuid();
            $recipient->status ??= 'queued';
            $recipient->email = Str::lower($recipient->email);
        });

        static::saving(function (NewsletterSendRecipient $recipient): void {
            if (! in_array($recipient->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid newsletter send recipient status [{$recipient->status}].");
            }

            $recipient->email = Str::lower($recipient->email);
            $recipient->validateAudienceMember();
        });
    }

    public function newsletterSend(): BelongsTo
    {
        return $this->belongsTo(NewsletterSend::class);
    }

    public function audienceMember(): BelongsTo
    {
        return $this->belongsTo(AudienceMember::class);
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    private function validateAudienceMember(): void
    {
        if ($this->audience_member_id === null) {
            return;
        }

        $send = $this->newsletterSend ?: NewsletterSend::query()->find($this->newsletter_send_id);
        $member = AudienceMember::query()->find($this->audience_member_id);

        if (! $send || ! $member || $member->account_id !== $send->account_id) {
            throw new InvalidArgumentException('Newsletter send recipient audience member must belong to the same account.');
        }
    }
}
