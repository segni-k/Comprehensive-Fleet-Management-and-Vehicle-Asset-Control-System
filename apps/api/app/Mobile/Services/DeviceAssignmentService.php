<?php

namespace App\Mobile\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Fleet\Models\Driver;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Mobile\Models\DriverDeviceAssignment;
use App\Mobile\Models\MobileDevice;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeviceAssignmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    /**
     * Assign a device to a driver.
     *
     * @param array<string,mixed> $data
     */
    public function assign(
        MobileDevice $device,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): DriverDeviceAssignment {
        $driverId       = $data['driver_id'];
        $assignmentType = $data['assignment_type'] ?? 'primary';

        return DB::transaction(function () use ($device, $data, $driverId, $assignmentType, $actor, $session, $request): DriverDeviceAssignment {
            // Verify driver exists and belongs to same org
            $driver = Driver::query()
                ->where('id', $driverId)
                ->where('organization_id', $device->organization_id)
                ->firstOrFail();

            // For primary assignments: ensure no other active primary device for this driver
            if ($assignmentType === 'primary') {
                $existingPrimary = DriverDeviceAssignment::query()
                    ->where('organization_id', $device->organization_id)
                    ->where('driver_id', $driverId)
                    ->where('assignment_type', 'primary')
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($existingPrimary !== null) {
                    throw new BusinessRuleException('DRIVER_ALREADY_HAS_ACTIVE_PRIMARY_DEVICE');
                }
            }

            $assignment = DriverDeviceAssignment::query()->create([
                'id'               => (string) Str::ulid(),
                'organization_id'  => $device->organization_id,
                'mobile_device_id' => $device->id,
                'driver_id'        => $driverId,
                'assignment_type'  => $assignmentType,
                'reason'           => $data['reason'] ?? null,
                'effective_from'   => $data['effective_from'] ?? now(),
                'assigned_by'      => $actor->id,
                'status'           => 'active',
            ]);

            // Update device driver_id
            $device->update(['driver_id' => $driverId]);

            $this->audit->record(
                eventType: 'device.assignment.created',
                category: 'mobile_device',
                action: 'assign_device',
                outcome: 'success',
                subjectType: 'mobile_device',
                subjectId: $device->id,
                organizationId: $device->organization_id,
                actor: $actor,
                session: $session,
                metadata: ['driver_id' => $driverId, 'assignment_type' => $assignmentType],
                request: $request,
            );

            $this->outbox->dispatch('mobile.device.assigned', [
                'assignment_id'    => $assignment->id,
                'mobile_device_id' => $device->id,
                'driver_id'        => $driverId,
                'organization_id'  => $device->organization_id,
            ]);

            return $assignment->load(['device', 'driver']);
        });
    }

    /**
     * End an active assignment.
     *
     * @param array<string,mixed> $data
     */
    public function endAssignment(
        DriverDeviceAssignment $assignment,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): DriverDeviceAssignment {
        if (! $assignment->isActive()) {
            throw new BusinessRuleException('ASSIGNMENT_NOT_ACTIVE');
        }

        return DB::transaction(function () use ($assignment, $data, $actor, $session, $request): DriverDeviceAssignment {
            $assignment->update([
                'status'          => 'ended',
                'effective_to'    => now(),
                'ended_by'        => $actor->id,
                'end_reason'      => $data['reason'],
                'record_version'  => DB::raw('record_version + 1'),
            ]);

            $this->audit->record(
                eventType: 'device.assignment.ended',
                category: 'mobile_device',
                action: 'end_device_assignment',
                outcome: 'success',
                subjectType: 'driver_device_assignment',
                subjectId: $assignment->id,
                organizationId: $assignment->organization_id,
                actor: $actor,
                session: $session,
                reason: $data['reason'],
                request: $request,
            );

            return $assignment->fresh();
        });
    }
}
