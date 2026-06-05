<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentFeedback extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'content_feedback';

    protected $fillable = [
        'content_id',
        'revision_id',
        'type',
        'message',
        'context',
        'created_by_user_id',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function revision()
    {
        return $this->belongsTo(ContentRevision::class, 'revision_id');
    }
}
