<?php

use App\Support\AdminFailedJobsQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

it('does not apply text filters when request inputs are empty strings', function () {
    $request = Request::create('/admin/queues', 'GET', [
        'range' => '24h',
        'from' => '',
        'to' => '',
        'queue' => '',
        'job_class' => '',
        'org_site' => '',
    ]);

    $filters = AdminFailedJobsQuery::resolveFilters($request);
    [$from, $to] = AdminFailedJobsQuery::resolveDateRange($request, $filters);

    $query = DB::table('failed_jobs');
    AdminFailedJobsQuery::applyFilters($query, $request, $filters, $from, $to);

    $sql = strtolower($query->toSql());

    expect($sql)->toContain('failed_at')
        ->and($sql)->toContain('between')
        ->and($sql)->not->toContain(' like ')
        ->and($sql)->not->toContain(' queue = ')
        ->and(count($query->getBindings()))->toBe(2);
});
