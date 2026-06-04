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
    'user_id',
    'provider',
    'model',
    'purpose',
    'status',
    'prompt_tokens',
    'completion_tokens',
    'total_tokens',
    'estimated_cost',
    'credits_charged',
    'latency_ms',
    'error_message',
    'metadata',
    'completed_at',
])]
class LlmRequest extends Model
{
    use HasFactory;

    public const PURPOSES = [
        'content_generation',
        'translation',
        'answer_block',
        'audit',
        'visibility_check',
        'social_post',
        'newsletter',
        'agent_task',
        'briefing_execution',
        'url_to_draft',
        'chained_content',
        'agentic_marketing',
    ];

    public const STATUSES = ['running', 'completed', 'failed'];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (LlmRequest $request): void {
            $request->uuid ??= (string) Str::uuid();
            $request->status ??= 'running';
            $request->credits_charged ??= 0;
        });

        static::saving(function (LlmRequest $request): void {
            if (! in_array($request->purpose, self::PURPOSES, true)) {
                throw new InvalidArgumentException("Invalid LLM request purpose [{$request->purpose}].");
            }

            if (! in_array($request->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Invalid LLM request status [{$request->status}].");
            }

            $request->validateBrand();
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'estimated_cost' => 'decimal:6',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    private function validateBrand(): void
    {
        if ($this->brand_id === null) {
            return;
        }

        $brand = Brand::query()->find($this->brand_id);

        if (! $brand || $this->account_id === null || $brand->account_id !== (int) $this->account_id) {
            throw new InvalidArgumentException('LLM request brand must belong to the same account.');
        }
    }
}
