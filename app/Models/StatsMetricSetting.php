<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatsMetricSetting extends Model
{
    protected $table = 'stats_metric_settings';

    protected $fillable = [
        'metric_key',
        'settings_json',
        'calculated_at',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'calculated_at' => 'datetime',
    ];
}
