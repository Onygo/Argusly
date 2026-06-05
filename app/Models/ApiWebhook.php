<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiWebhook extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'content_destination_id',
        'name',
        'target_url',
        'secret',
        'events',
        'is_active',
        'last_delivered_at',
        'last_failure_at',
        'created_by',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'last_delivered_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contentDestination()
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries()
    {
        return $this->hasMany(ApiWebhookDelivery::class);
    }

    public function subscribesTo(string $event): bool
    {
        $events = is_array($this->events) ? $this->events : [];

        return in_array('*', $events, true) || in_array($event, $events, true);
    }
}
