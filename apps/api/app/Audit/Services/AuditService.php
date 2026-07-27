<?php

namespace App\Audit\Services;

use App\Audit\Models\AuditChainCheckpoint;
use App\Audit\Models\AuditEvent;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuditService
{
    public function __construct(private readonly AuditRedactor $redactor) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $eventType,
        string $category,
        string $action,
        string $outcome,
        string $subjectType,
        ?string $subjectId,
        ?string $organizationId = null,
        ?User $actor = null,
        ?UserSession $session = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
        string $severity = 'information',
        string $priority = 'normal',
        ?string $workflowReference = null,
        ?Request $request = null,
    ): AuditEvent {
        return DB::transaction(function () use ($eventType, $category, $action, $outcome, $subjectType, $subjectId, $organizationId, $actor, $session, $reason, $before, $after, $metadata, $severity, $priority, $workflowReference, $request): AuditEvent {
            $partition = $organizationId ?? 'platform';
            $checkpoint = AuditChainCheckpoint::query()
                ->where('partition_key', $partition)
                ->lockForUpdate()
                ->first();
            $sequence = $checkpoint === null ? 1 : $checkpoint->last_sequence + 1;
            $safeBefore = $this->redactor->redact($before);
            $safeAfter = $this->redactor->redact($after);
            $safeMetadata = $this->redactor->redact($metadata);
            // Laravel's default database date format is second-precision. Hash
            // the canonical persisted value so verification is driver-neutral.
            $occurredAt = now()->setMicrosecond(0);
            $eventHash = $this->hash([
                'partition' => $partition,
                'sequence' => $sequence,
                'event_type' => $eventType,
                'actor' => $actor?->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'outcome' => $outcome,
                'before' => $safeBefore,
                'after' => $safeAfter,
                'metadata' => $safeMetadata,
                'occurred_at' => $occurredAt->toISOString(),
                'previous_hash' => $checkpoint?->last_event_hash,
            ]);
            $event = AuditEvent::query()->create([
                'sequence' => $sequence,
                'partition_key' => $partition,
                'event_type' => $eventType,
                'category' => $category,
                'action' => $action,
                'outcome' => $outcome,
                'severity' => $severity,
                'priority' => $priority,
                'actor_user_id' => $actor?->id,
                'actor_session_id' => $session?->id,
                'organization_id' => $organizationId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'request_id' => $request?->attributes->get('request_id'),
                'correlation_id' => $request?->attributes->get('correlation_id'),
                'causation_id' => $request?->attributes->get('causation_id'),
                'ip_hash' => $request ? hash('sha256', (string) $request->ip()) : null,
                'user_agent_hash' => $request ? hash('sha256', (string) $request->userAgent()) : null,
                'reason' => $reason,
                'workflow_reference' => $workflowReference,
                'before_snapshot' => $safeBefore,
                'after_snapshot' => $safeAfter,
                'changed_fields' => $this->redactor->changedFields($safeBefore, $safeAfter),
                'metadata' => $safeMetadata,
                'previous_hash' => $checkpoint?->last_event_hash,
                'event_hash' => $eventHash,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
            ]);
            AuditChainCheckpoint::query()->updateOrCreate(['partition_key' => $partition], [
                'last_sequence' => $sequence,
                'last_event_hash' => $eventHash,
                'verification_status' => 'verified',
                'verified_at' => $occurredAt,
                'verification_details' => ['mode' => 'write_time_chain'],
            ]);

            return $event;
        });
    }

    /** @return array{valid:bool,checked:int,first_invalid_sequence:int|null} */
    public function verify(string $partition): array
    {
        $previous = null;
        $checked = 0;
        foreach (AuditEvent::query()->where('partition_key', $partition)->orderBy('sequence')->cursor() as $event) {
            $expectedHash = $this->hash([
                'partition' => $event->partition_key,
                'sequence' => $event->sequence,
                'event_type' => $event->event_type,
                'actor' => $event->actor_user_id,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'outcome' => $event->outcome,
                'before' => $event->before_snapshot,
                'after' => $event->after_snapshot,
                'metadata' => $event->metadata,
                'occurred_at' => $event->occurred_at->toISOString(),
                'previous_hash' => $event->previous_hash,
            ]);
            if ($event->previous_hash !== $previous || ! hash_equals($expectedHash, $event->event_hash)) {
                return ['valid' => false, 'checked' => $checked, 'first_invalid_sequence' => $event->sequence];
            }
            $previous = $event->event_hash;
            $checked++;
        }

        return ['valid' => true, 'checked' => $checked, 'first_invalid_sequence' => null];
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
