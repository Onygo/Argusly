@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<rss version="2.0">
<channel>
    <title>{{ $feedTitle }}</title>
    <link>{{ $feedLink }}</link>
    <description>{{ $feedDescription }}</description>
    <language>{{ app()->getLocale() }}</language>
    @foreach($posts as $post)
        <item>
            <title><![CDATA[{{ $post['title'] }}]]></title>
            <link>{{ \App\Support\LocalizedMarketingUrl::route('public.blog.show', ['slug' => $post['slug']], (string) app()->getLocale()) }}</link>
            <guid isPermaLink="true">{{ \App\Support\LocalizedMarketingUrl::route('public.blog.show', ['slug' => $post['slug']], (string) app()->getLocale()) }}</guid>
            <pubDate>{{ \Carbon\Carbon::parse($post['published_at'])->toRssString() }}</pubDate>
            <description><![CDATA[{{ $post['excerpt'] }}]]></description>
        </item>
    @endforeach
</channel>
</rss>
