<?php

namespace App\Http\Controllers\Operations;

use App\Audit\Models\AuditEvent;
use App\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditController extends Controller
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'category' => ['nullable', 'string', 'max:80'],
            'severity' => ['nullable', 'in:information,warning,error,critical'],
            'outcome' => ['nullable', 'string', 'max:40'],
            'event_type' => ['nullable', 'string', 'max:190'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'string', 'size:26'],
            'correlation_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeOrganization($request, 'audit.event.view', $filters['organization_id']);
        $events = AuditEvent::query()
            ->where('organization_id', $filters['organization_id'])
            ->when($filters['category'] ?? null, fn ($query, $value) => $query->where('category', $value))
            ->when($filters['severity'] ?? null, fn ($query, $value) => $query->where('severity', $value))
            ->when($filters['outcome'] ?? null, fn ($query, $value) => $query->where('outcome', $value))
            ->when($filters['event_type'] ?? null, fn ($query, $value) => $query->where('event_type', $value))
            ->when($filters['subject_type'] ?? null, fn ($query, $value) => $query->where('subject_type', $value))
            ->when($filters['subject_id'] ?? null, fn ($query, $value) => $query->where('subject_id', $value))
            ->when($filters['correlation_id'] ?? null, fn ($query, $value) => $query->where('correlation_id', $value))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->where('occurred_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->where('occurred_at', '<=', $value))
            ->latest('occurred_at')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json($events);
    }

    public function show(Request $request, AuditEvent $auditEvent): JsonResponse
    {
        abort_if($auditEvent->organization_id === null, 404);
        $this->authorizeOrganization($request, 'audit.event.view', $auditEvent->organization_id);

        return response()->json(['data' => $auditEvent]);
    }

    public function verify(Request $request, AuditService $audit): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string', 'exists:organizations,id']]);
        $this->authorizeOrganization($request, 'audit.integrity.verify', $data['organization_id']);

        return response()->json(['data' => $audit->verify($data['organization_id'])]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $this->authorizeOrganization($request, 'audit.event.export', $data['organization_id']);

        return response()->streamDownload(function () use ($data): void {
            $stream = fopen('php://output', 'w');
            abort_unless(is_resource($stream), 500);
            fputcsv($stream, ['sequence', 'event_type', 'category', 'action', 'outcome', 'severity', 'actor_user_id', 'subject_type', 'subject_id', 'reason', 'correlation_id', 'occurred_at', 'event_hash'], ',', '"', '');
            AuditEvent::query()
                ->where('organization_id', $data['organization_id'])
                ->when($data['from'] ?? null, fn ($query, $value) => $query->where('occurred_at', '>=', $value))
                ->when($data['to'] ?? null, fn ($query, $value) => $query->where('occurred_at', '<=', $value))
                ->orderBy('sequence')
                ->eachById(function (AuditEvent $event) use ($stream): void {
                    fputcsv($stream, [
                        $event->sequence, $event->event_type, $event->category, $event->action,
                        $event->outcome, $event->severity, $event->actor_user_id, $event->subject_type,
                        $event->subject_id, $event->reason, $event->correlation_id, $event->occurred_at?->toISOString(),
                        $event->event_hash,
                    ], ',', '"', '');
                });
            fclose($stream);
        }, 'audit-events.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeOrganization(Request $request, string $permission, string $organizationId): void
    {
        $session = $request->attributes->get('identity_session');
        abort_unless($session instanceof UserSession && $this->authorization->allows(
            $request->user(), $permission, $organizationId, null, null, $session,
        ), 403);
    }
}
