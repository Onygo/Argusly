<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Persona extends Model
{
    use HasFactory;

    public const TYPE_BUYER = 'buyer';
    public const TYPE_USER = 'user';
    public const TYPE_INFLUENCER = 'influencer';
    public const TYPE_DECISION_MAKER = 'decision_maker';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'organization_id',
        'generated_from_context_id',
        'type',
        'name',
        'source_type',
        'source_payload',
        'profile_data',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'source_payload' => 'array',
        'profile_data' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function generatedFromContext()
    {
        return $this->belongsTo(BrandContext::class, 'generated_from_context_id');
    }
}
