@props([
    'items' => collect(),
    'schema' => null,
    'title' => 'FAQ',
    'eyebrow' => 'FAQ',
])

<x-public.faq-section
    :items="$items"
    :schema="$schema"
    :heading="$title"
    :eyebrow="$eyebrow"
/>
