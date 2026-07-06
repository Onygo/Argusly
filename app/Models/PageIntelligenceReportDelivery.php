<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageIntelligenceReportDelivery extends Model
{
    use HasFactory;
    use HasUuids;

    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_EMAIL_PLACEHOLDER = 'email_placeholder';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'report_id',
        'scheduled_briefing_id',
        'workspace_id',
        'recipient_user_id',
        'recipient_email',
        'channel',
        'status',
        'attempt_count',
        'last_attempt_at',
        'delivered_at',
        'failed_at',
        'provider_message_id',
        'provider_status',
        'failure_category',
        'error',
        'metadata_json',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(PageIntelligenceReport::class, 'report_id');
    }

    public function scheduledBriefing(): BelongsTo
    {
        return $this->belongsTo(ScheduledPageIntelligenceBriefing::class, 'scheduled_briefing_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
