<?php

namespace App\Services\PublicBlog;

use App\Contracts\PublicBlogSource;
use App\Exceptions\PublicBlogSourceUnavailableException;

class ConnectorFirstBlogSource implements PublicBlogSource
{
    public function __construct(
        private readonly PublishLayerConnectorBlogSource $connector,
        private readonly ConnectorSynchronizedBlogSource $local
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchPublishedPosts(): array
    {
        $fallbackToLocal = (bool) config('publishlayer_connector.public_blog.fallback_to_local', config('publishlayer.public_blog.fallback_to_local', true));

        if (! $this->connector->isEnabled()) {
            return $this->local->fetchPublishedPosts();
        }

        try {
            return $this->connector->fetchPublishedPosts();
        } catch (PublicBlogSourceUnavailableException $e) {
            if (! $fallbackToLocal) {
                throw $e;
            }

            return $this->local->fetchPublishedPosts();
        }
    }
}
