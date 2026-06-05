<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleEntity extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_id',
        'entity',
        'entity_type',
        'confidence',
    ];

    protected $casts = [
        'confidence' => 'float',
    ];

    public function article()
    {
        return $this->belongsTo(Draft::class, 'article_id');
    }
}
