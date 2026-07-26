<?php

namespace App\Organization\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationAuditService
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $event,
        string $subjectType,
        string $subjectId,
        string $actor,
        ?string $organizationId = null,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        ?string $correlationId = null,
    ): void {
        DB::table('organization_hierarchy_change_history')->insert([
            'id' => (string) Str::ulid(),
            'event_type' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'organization_id' => $organizationId,
            'actor_reference' => $actor,
            'reason' => $reason,
            'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_snapshot' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'correlation_id' => $correlationId,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
