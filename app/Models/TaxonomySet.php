<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxonomySet extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(TaxonomyItem::class);
    }

    public function tenants()
    {
        return $this->belongsToMany(Organization::class, 'taxonomy_set_tenant', 'taxonomy_set_id', 'tenant_id')
            ->withTimestamps();
    }
}

