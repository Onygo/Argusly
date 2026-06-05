<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

class StructuredAnswerBlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => (string) $this->id,
            'question' => (string) $this->question,
            'answer' => (string) $this->answer,
            'entities' => array_values((array) ($this->entities ?? [])),
            'platforms' => array_values((array) ($this->platforms ?? [])),
            'order' => (int) $this->order,
        ];
    }
}
