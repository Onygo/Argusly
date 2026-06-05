<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditReservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CreditReservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AdminCreditReservationsController extends Controller
{
    private const STATUSES = [
        '' => 'All',
        'reserved' => 'Reserved',
        'captured' => 'Captured',
        'released' => 'Released',
        'expired' => 'Expired',
    ];

    private const PURPOSES = [
        '' => 'All',
        'draft_generate' => 'Draft Generation',
        'image_generate' => 'Image Generation',
        'series_generate' => 'Series Generation',
        'research_generate' => 'Research Generation',
    ];

    public function index(Request $request): View
    {
        $filters = $this->parseFilters($request);
        $query = $this->buildQuery($filters);

        $reservations = $query
            ->with(['user', 'adminUser', 'wallet.clientSite', 'organization'])
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $stats = $this->calculateStats();
        $organizations = Organization::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.credit-reservations.index', [
            'reservations' => $reservations,
            'filters' => $filters,
            'statuses' => self::STATUSES,
            'purposes' => self::PURPOSES,
            'organizations' => $organizations,
            'stats' => $stats,
        ]);
    }

    public function show(CreditReservation $reservation): View
    {
        $reservation->load([
            'user',
            'adminUser',
            'wallet.clientSite',
            'organization',
            'reservationLedgerEntry',
            'captureLedgerEntry',
            'releaseLedgerEntry',
            'context',
        ]);

        $contextPreview = $this->buildContextPreview($reservation);

        return view('admin.credit-reservations.show', [
            'reservation' => $reservation,
            'contextPreview' => $contextPreview,
        ]);
    }

    public function release(
        Request $request,
        CreditReservation $reservation,
        CreditReservationService $reservationService,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if (! $reservation->isReserved()) {
            return back()->withErrors([
                'reservation' => 'Cannot release: reservation is not in reserved status.',
            ]);
        }

        $before = [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'amount' => $reservation->amount,
        ];

        try {
            $reservationService->adminRelease(
                $reservation,
                $request->user()->id,
                $data['reason']
            );

            $reservation->refresh();

            $auditLogs->log(
                actor: $request->user(),
                subject: $reservation,
                action: 'credit_reservation.admin_release',
                before: $before,
                after: [
                    'id' => $reservation->id,
                    'status' => $reservation->status,
                    'reason' => $data['reason'],
                ],
                request: $request
            );

            return back()->with('status', sprintf(
                'Released %d credits from reservation %s.',
                $reservation->amount,
                substr($reservation->id, 0, 8)
            ));
        } catch (\Throwable $e) {
            return back()->withErrors(['reservation' => $e->getMessage()]);
        }
    }

    public function capture(
        Request $request,
        CreditReservation $reservation,
        CreditReservationService $reservationService,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if (! $reservation->isReserved()) {
            return back()->withErrors([
                'reservation' => 'Cannot capture: reservation is not in reserved status.',
            ]);
        }

        $before = [
            'id' => $reservation->id,
            'status' => $reservation->status,
            'amount' => $reservation->amount,
        ];

        try {
            $reservationService->adminCapture(
                $reservation,
                $request->user()->id,
                $data['reason']
            );

            $reservation->refresh();

            $auditLogs->log(
                actor: $request->user(),
                subject: $reservation,
                action: 'credit_reservation.admin_capture',
                before: $before,
                after: [
                    'id' => $reservation->id,
                    'status' => $reservation->status,
                    'reason' => $data['reason'],
                ],
                request: $request
            );

            return back()->with('status', sprintf(
                'Captured %d credits from reservation %s.',
                $reservation->amount,
                substr($reservation->id, 0, 8)
            ));
        } catch (\Throwable $e) {
            return back()->withErrors(['reservation' => $e->getMessage()]);
        }
    }

    public function bulkRelease(
        Request $request,
        CreditReservationService $reservationService,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $data = $request->validate([
            'reservation_ids' => ['required', 'array', 'min:1'],
            'reservation_ids.*' => ['required', 'string', 'uuid'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $releasedCount = 0;
        $errors = [];

        foreach ($data['reservation_ids'] as $reservationId) {
            $reservation = CreditReservation::find($reservationId);

            if (! $reservation || ! $reservation->isReserved()) {
                $errors[] = "Reservation {$reservationId} not found or not in reserved status.";

                continue;
            }

            try {
                $reservationService->adminRelease(
                    $reservation,
                    $request->user()->id,
                    $data['reason']
                );

                $auditLogs->log(
                    actor: $request->user(),
                    subject: $reservation,
                    action: 'credit_reservation.admin_bulk_release',
                    before: ['status' => 'reserved'],
                    after: ['status' => 'released', 'reason' => $data['reason']],
                    request: $request
                );

                $releasedCount++;
            } catch (\Throwable $e) {
                $errors[] = "Failed to release {$reservationId}: {$e->getMessage()}";
            }
        }

        $message = "Released {$releasedCount} reservation(s).";
        if (count($errors) > 0) {
            $message .= ' Errors: ' . implode('; ', array_slice($errors, 0, 3));
        }

        return back()->with('status', $message);
    }

    public function expireStale(
        Request $request,
        CreditReservationService $reservationService,
        AuditLogService $auditLogs
    ): RedirectResponse {
        $expired = $reservationService->expireStaleReservations(100);

        $auditLogs->log(
            actor: $request->user(),
            subject: null,
            action: 'credit_reservation.admin_expire_stale',
            before: [],
            after: ['expired_count' => $expired],
            request: $request
        );

        return back()->with('status', "Expired {$expired} stale reservation(s).");
    }

    private function parseFilters(Request $request): array
    {
        return [
            'status' => (string) $request->query('status', ''),
            'purpose' => (string) $request->query('purpose', ''),
            'organization_id' => (string) $request->query('organization_id', ''),
            'user_id' => (string) $request->query('user_id', ''),
            'provider' => (string) $request->query('provider', ''),
            'context_id' => (string) $request->query('context_id', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'q' => trim((string) $request->query('q', '')),
            'stale_only' => (bool) $request->query('stale_only', false),
        ];
    }

    private function buildQuery(array $filters): Builder
    {
        $query = CreditReservation::query();

        if ($filters['status'] !== '' && array_key_exists($filters['status'], self::STATUSES)) {
            $query->where('status', $filters['status']);
        }

        if ($filters['purpose'] !== '' && array_key_exists($filters['purpose'], self::PURPOSES)) {
            $query->where('purpose', $filters['purpose']);
        }

        if ($filters['organization_id'] !== '') {
            $query->where('organization_id', $filters['organization_id']);
        }

        if ($filters['user_id'] !== '') {
            $query->where('user_id', $filters['user_id']);
        }

        if ($filters['provider'] !== '') {
            $query->where('provider', $filters['provider']);
        }

        if ($filters['context_id'] !== '') {
            $query->where('context_id', $filters['context_id']);
        }

        if ($filters['from'] !== '' && $this->isValidDate($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if ($filters['to'] !== '' && $this->isValidDate($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        if ($filters['q'] !== '') {
            $query->where(function (Builder $builder) use ($filters) {
                $builder
                    ->where('id', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('idempotency_key', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('context_id', 'like', '%' . $filters['q'] . '%')
                    ->orWhere('failure_message', 'like', '%' . $filters['q'] . '%');
            });
        }

        if ($filters['stale_only']) {
            $query->stale();
        }

        return $query;
    }

    private function calculateStats(): array
    {
        return [
            'total_reserved' => CreditReservation::reserved()->count(),
            'total_reserved_credits' => (int) CreditReservation::reserved()->sum('amount'),
            'stale_count' => CreditReservation::stale()->count(),
            'stale_credits' => (int) CreditReservation::stale()->sum('amount'),
            'captured_today' => CreditReservation::captured()
                ->whereDate('captured_at', today())
                ->count(),
            'released_today' => CreditReservation::released()
                ->whereDate('released_at', today())
                ->count(),
        ];
    }

    private function buildContextPreview(CreditReservation $reservation): ?array
    {
        $context = $reservation->context;

        if (! $context) {
            return null;
        }

        $preview = [
            'type' => class_basename($context),
            'id' => $context->id,
        ];

        // Add type-specific details
        if ($context instanceof \App\Models\Draft) {
            $preview['status'] = $context->status;
            $preview['output_preview'] = $context->output
                ? \Illuminate\Support\Str::limit($context->output, 200)
                : null;
            $preview['has_output'] = ! empty($context->output);
        } elseif ($context instanceof \App\Models\ContentImage) {
            $preview['status'] = $context->status;
            $preview['has_output'] = $context->hasOutput();
            $preview['image_path'] = $context->image_path;
        } elseif ($context instanceof \App\Models\Content) {
            $preview['title'] = $context->title;
            $preview['status'] = $context->status;
        }

        return $preview;
    }

    private function isValidDate(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
