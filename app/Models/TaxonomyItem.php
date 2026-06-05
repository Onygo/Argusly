<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxonomyItem extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const TYPE_INTENT = 'intent';
    public const TYPE_AUDIENCE = 'audience';

    protected $fillable = [
        'taxonomy_set_id',
        'type',
        'name',
        'slug',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'parent_id' => 'integer',
    ];

    /**
     * @return array<int, string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_INTENT,
            self::TYPE_AUDIENCE,
        ];
    }

    public function set()
    {
        return $this->belongsTo(TaxonomySet::class, 'taxonomy_set_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}

