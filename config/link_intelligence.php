<?php

return [
    'embedding' => [
        // Replace with a real provider class when integrating external embeddings.
        'service' => env('LINK_INTELLIGENCE_EMBEDDING_SERVICE', App\Services\LinkIntelligence\Mocks\LocalMockEmbeddingService::class),
        'model' => env('LINK_INTELLIGENCE_EMBEDDING_MODEL', 'local-mock-embedding-v1'),
    ],

    'entity_extraction' => [
        // Replace with a real provider class when integrating NER extraction.
        'service' => env('LINK_INTELLIGENCE_ENTITY_SERVICE', App\Services\LinkIntelligence\Mocks\LocalMockEntityExtractionService::class),
    ],

    'limits' => [
        'footnote_block_heading' => 'Related reading',
        'max_anchor_variants' => 5,
    ],
];
