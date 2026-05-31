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
    'section_type',
    'title',
    'summary',
    'payload',
    'position',
])]
class ReportSection extends Model
{
    use HasFactory;

    public const TYPES = [
        'ai_visibility',
        'content_performance',
        'search_performance',
        'social_distribution',
        'recommendations',
        'risks',
        'wins',
        'next_actions',
    ];

    protected static function booted(): void
    {
        static::creating(function (ReportSection $section): void {
            $section->uuid ??= (string) Str::uuid();
        });

        static::saving(function (ReportSection $section): void {
            if (! in_array($section->section_type, self::TYPES, true)) {
                throw new InvalidArgumentException("Invalid report section type [{$section->section_type}].");
            }

            $report = Report::query()->find($section->report_id);

            if (! $report || $report->account_id !== $section->account_id || $report->brand_id !== $section->brand_id) {
                throw new InvalidArgumentException('Report section must belong to the same report tenant.');
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
            'position' => 'integer',
        ];
    }
}
