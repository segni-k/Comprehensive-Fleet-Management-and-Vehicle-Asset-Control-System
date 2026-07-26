<?php

namespace App\Identity\Services;

use App\Identity\Models\IdentityAuditEvent;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use Illuminate\Http\Request;

final class IdentityAuditService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $eventType,
        string $subjectType,
        ?string $subjectId,
        string $outcome,
        ?User $actor = null,
        ?UserSession $session = null,
        ?string $organizationId = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        string $priority = 'normal',
        ?Request $request = null,
    ): IdentityAuditEvent {
        return IdentityAuditEvent::query()->create([
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'actor_session_id' => $session?->id,
            'organization_id' => $organizationId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'outcome' => $outcome,
            'priority' => $priority,
            'reason' => $reason,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'correlation_id' => $request?->attributes->get('correlation_id'),
            'ip_hash' => $request ? hash('sha256', (string) $request->ip()) : null,
            'occurred_at' => now(),
        ]);
    }
}
