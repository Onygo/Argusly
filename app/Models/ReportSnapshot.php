<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'report_id',
    'account_id',
    'brand_id',
    'payload',
    'html',
    'generated_at',
])]
class ReportSnapshot extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (ReportSnapshot $snapshot): void {
            $snapshot->uuid ??= (string) Str::uuid();
            $snapshot->generated_at ??= now();
        });

        static::saving(function (ReportSnapshot $snapshot): void {
            $report = Report::query()->find($snapshot->report_id);

            if (! $report || $report->account_id !== $snapshot->account_id || $report->brand_id !== $snapshot->brand_id) {
                throw new InvalidArgumentException('Report snapshot must belong to the same report tenant.');
            }
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
