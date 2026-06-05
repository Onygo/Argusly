<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentPolicyDefinition extends Model
{
    protected $table = 'content_policies';

    protected $fillable = [
        'name',
        'description',
        'rules',
    ];

    protected $casts = [
        'rules' => 'array',
    ];
}
