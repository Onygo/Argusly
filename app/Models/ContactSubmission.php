<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSubmission extends Model
{
    protected $fillable = [
        'name',
        'email',
        'company',
        'website',
        'market',
        'competitors',
        'growth_goal',
        'interest_area',
        'subject',
        'message',
        'topic',
        'source_page',
        'cta_label',
        'url',
        'ip_address',
        'user_agent',
        'mail_sent_at',
        'mail_error',
    ];

    protected $casts = [
        'mail_sent_at' => 'datetime',
    ];
}
