<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Resources\Json\JsonResource;

class MarkdownIndexItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        /** @var array{content:\App\Models\Content,artifact:\App\Models\ContentRenderArtifact,site:\App\Models\ClientSite,locale:string} $item */
        $item = $this->resource;

        return [
            'slug' => (new MarkdownArtifactResource($item['artifact']))->toArray($request)['slug'],
            'locale' => $item['locale'],
            'markdown_url' => route('api.sites.content.markdown', [
                'site' => $item['site']->id,
                'content' => $item['content']->id,
                'locale' => $item['locale'],
            ]),
            'html_url' => route('api.sites.content.html', [
                'site' => $item['site']->id,
                'content' => $item['content']->id,
                'locale' => $item['locale'],
            ]),
            'answers_url' => route('api.sites.content.answers', [
                'site' => $item['site']->id,
                'content' => $item['content']->id,
                'locale' => $item['locale'],
            ]),
            'updated_at' => optional($item['content']->updated_at)->toIso8601String(),
        ];
    }
}
