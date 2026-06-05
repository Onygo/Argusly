<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    use BelongsToOrganization;
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'organization_id',
        'generated_from_context_id',
        'name',
        'title',
        'email',
        'public_profile_url',
        'bio_source_text',
        'source_payload',
        'profile_data',
        'status',
        'created_by',
        'updated_by',
        'role',
        'expertise',
        'writing_perspective',
        'personality_traits',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
