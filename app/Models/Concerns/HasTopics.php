<?php

namespace App\Models\Concerns;

use App\Models\Topic;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasTopics
{
    /**
     * @return MorphToMany<Topic, $this>
     */
    public function topics(): MorphToMany
    {
        return $this->morphToMany(Topic::class, 'topicable')
            ->withPivot(['account_id', 'brand_id', 'relationship_type', 'relevance_score'])
            ->withTimestamps();
    }
}
