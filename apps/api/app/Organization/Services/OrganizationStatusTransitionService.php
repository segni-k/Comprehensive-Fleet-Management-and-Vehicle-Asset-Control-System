<?php

namespace App\Organization\Services;

use Illuminate\Support\Facades\DB;

final class OrganizationStatusTransitionService
{
    public function __construct(private readonly OrganizationAuditService $audit) {}

    public function applyDue(): int
    {
        $applied = 0;

        DB::transaction(function () use (&$applied): void {
            $transitions = DB::table('organization_status_transitions')
                ->where('status', 'scheduled')
                ->where('effective_at', '<=', now())
                ->orderBy('effective_at')
                ->lockForUpdate()
                ->get();

            foreach ($transitions as $transition) {
                $table = $transition->subject_type === 'organization_type'
                    ? 'organization_types'
                    : 'organizations';
                $values = [
                    'status' => $transition->target_status,
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => now(),
                ];
                if ($transition->subject_type === 'organization_type') {
                    $values['configuration_status'] = $transition->target_status === 'active'
                        ? 'approved'
                        : 'retired';
                }
                $updated = DB::table($table)
                    ->where('id', $transition->subject_id)
                    ->update($values);
                if ($updated !== 1) {
                    continue;
                }

                DB::table('organization_status_transitions')
                    ->where('id', $transition->id)
                    ->update([
                        'status' => 'applied',
                        'record_version' => DB::raw('record_version + 1'),
                        'updated_at' => now(),
                    ]);
                $this->audit->record(
                    "{$transition->subject_type}.{$transition->target_status}.applied",
                    'organization_status_transition',
                    (string) $transition->id,
                    'system:organization-status-scheduler',
                    $transition->subject_type === 'organization' ? (string) $transition->subject_id : null,
                    (string) $transition->reason,
                    null,
                    ['target_status' => $transition->target_status, 'effective_at' => $transition->effective_at],
                    null,
                );
                $applied++;
            }
        }, 3);

        return $applied;
    }
}
