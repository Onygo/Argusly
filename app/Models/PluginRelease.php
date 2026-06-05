<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PluginRelease extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'min_wp_version',
        'tested_wp_version',
        'zip_storage_path',
        'is_security_release',
    ];

    protected $casts = [
        'is_security_release' => 'boolean',
    ];
}

