<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrossLinkPermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_workspace_id',
        'to_workspace_id',
        'status',
        'relationship_type',
        'rel_attribute',
        'approved_by_user_id',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function fromWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'from_workspace_id');
    }

    public function toWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'to_workspace_id');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
