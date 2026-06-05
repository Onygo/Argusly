<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArticleEmbedding extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_id',
        'workspace_id',
        'client_site_id',
        'embedding_provider',
        'embedding_model',
        'embedding_json',
    ];

    protected $casts = [
        'embedding_json' => 'array',
    ];

    public function article()
    {
        return $this->belongsTo(Draft::class, 'article_id');
    }
}
