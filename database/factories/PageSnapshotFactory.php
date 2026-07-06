<?php

namespace Database\Factories;

use App\Models\MonitoredPage;
use App\Models\PageSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageSnapshot>
 */
class PageSnapshotFactory extends Factory
{
    protected $model = PageSnapshot::class;

    public function definition(): array
    {
        $page = MonitoredPage::factory()->create();
        $html = '<html><head><title>Page intelligence market update</title></head><body>Argusly page evidence.</body></html>';
        $text = 'Argusly page evidence.';

        return [
            'organization_id' => $page->organization_id,
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'monitored_page_id' => $page->id,
            'snapshot_number' => 1,
            'requested_url' => $page->first_seen_url,
            'final_url' => $page->final_url,
            'canonical_url' => $page->canonical_url,
            'http_status' => 200,
            'content_type' => 'text/html; charset=UTF-8',
            'response_headers_json' => ['content-type' => 'text/html; charset=UTF-8'],
            'redirect_chain_json' => [],
            'raw_html_path' => 'page-snapshots/'.$page->id.'/1.html',
            'raw_html' => $html,
            'raw_html_bytes' => strlen($html),
            'raw_html_preview' => $html,
            'raw_html_hash' => hash('sha256', $html),
            'text_hash' => hash('sha256', $text),
            'content_changed' => true,
            'canonical_conflict' => false,
            'fetch_duration_ms' => 145,
            'fetched_at' => now()->subMinutes(30),
            'fetcher_version' => 'factory-v1',
            'metadata_json' => ['factory' => true],
        ];
    }

    public function forPage(MonitoredPage $page, int $snapshotNumber = 1): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $page->organization_id,
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'monitored_page_id' => $page->id,
            'snapshot_number' => $snapshotNumber,
            'requested_url' => $page->first_seen_url,
            'final_url' => $page->final_url,
            'canonical_url' => $page->canonical_url,
        ]);
    }
}
