<?php

use App\Support\RuntimeHtmlTranslator;
use Symfony\Component\HttpFoundation\Response;

it('keeps script template literals intact while translating html', function () {
    $html = <<<'HTML'
<html><body>
<h1>Billing</h1>
<script>
const row = (label) => `<div><p>${label}</p></div>`;
document.body.dataset.ready = 'yes';
</script>
</body></html>
HTML;

    $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

    $translated = app(RuntimeHtmlTranslator::class)->translateResponse($response, [
        'Billing' => 'Facturatie',
    ])->getContent();

    expect($translated)->toContain('<h1>Facturatie</h1>')
        ->and($translated)->toContain('const row = (label) => `<div><p>${label}</p></div>`;')
        ->and($translated)->toContain("<script>\nconst row");
});

it('translates billing copy and dynamic billing patterns', function () {
    $html = <<<'HTML'
<html><body>
<h1>Billing & Credits</h1>
<p>Workspace credits available</p>
<p>Billing profile</p>
<input placeholder="KvK number">
<button>Save billing details</button>
<p>Current plan:</p>
<p>Allocation 310 · Remaining 286 · Reserved 24 · Used 320</p>
<p>Workspace unallocated pool: 0</p>
<option>Switch next period</option>
</body></html>
HTML;

    $response = new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);

    $translations = trans('app.runtime', [], 'nl');
    expect($translations)->toBeArray();

    $translated = app(RuntimeHtmlTranslator::class)->translateResponse($response, $translations)->getContent();

    expect($translated)->toContain('<h1>Facturatie &amp; credits</h1>')
        ->and($translated)->toContain('Beschikbare workspace-credits')
        ->and($translated)->toContain('Facturatieprofiel')
        ->and($translated)->toContain('placeholder="KvK-nummer"')
        ->and($translated)->toContain('Facturatiegegevens opslaan')
        ->and($translated)->toContain('Huidig plan:')
        ->and($translated)->toContain('Allocatie 310 &middot; Resterend 286 &middot; Gereserveerd 24 &middot; Gebruikt 320')
        ->and($translated)->toContain('Niet-toegewezen workspace-pool: 0')
        ->and($translated)->toContain('Vanaf volgende periode');
});
