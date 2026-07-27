<?php

namespace App\Fleet\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Fleet\Models\Driver;
use App\Fleet\Models\Vehicle;
use App\Fleet\Models\VehicleDriverAssignment;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VehicleAssignmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function assign(array $data, User $actor, ?UserSession $session, Request $request): VehicleDriverAssignment
    {
        return DB::transaction(function () use ($data, $actor, $session, $request): VehicleDriverAssignment {
            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->whereKey($data['vehicle_id'])->lockForUpdate()->firstOrFail();
            /** @var Driver $driver */
            $driver = Driver::query()->whereKey($data['driver_id'])->lockForUpdate()->firstOrFail();
            $this->assertEligibility($vehicle, $driver, $data);
            $assignment = VehicleDriverAssignment::query()->create([
                ...$data,
                'assigned_by' => $actor->id,
                'status' => 'active',
            ]);
            $driver->forceFill(['availability_status' => 'assigned', 'record_version' => $driver->record_version + 1])->save();
            $this->record('vehicle_driver_assignment.created', 'create', $assignment, $actor, $session, $request, null, $assignment->toArray(), $data['reason']);

            return $assignment->load(['vehicle', 'driver']);
        }, 3);
    }

    public function close(
        VehicleDriverAssignment $assignment,
        int $recordVersion,
        string $reason,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): VehicleDriverAssignment {
        return DB::transaction(function () use ($assignment, $recordVersion, $reason, $actor, $session, $request): VehicleDriverAssignment {
            /** @var VehicleDriverAssignment $locked */
            $locked = VehicleDriverAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new BusinessRuleException('ASSIGNMENT_ALREADY_CLOSED', 'Only an active assignment can be closed.');
            }
            if ($locked->record_version !== $recordVersion) {
                throw new ConflictException('FLEET_RECORD_VERSION_CONFLICT', 'The assignment was changed by another operation.');
            }
            $before = $locked->only(['status', 'ends_at', 'record_version']);
            $locked->forceFill([
                'status' => 'closed',
                'ends_at' => $locked->ends_at ?? now(),
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'closure_reason' => $reason,
                'record_version' => $locked->record_version + 1,
            ])->save();
            if (! VehicleDriverAssignment::query()->where('driver_id', $locked->driver_id)->where('status', 'active')->exists()) {
                Driver::query()->whereKey($locked->driver_id)->update(['availability_status' => 'available']);
            }
            $this->record('vehicle_driver_assignment.closed', 'close', $locked, $actor, $session, $request, $before, $locked->only([
                'status', 'ends_at', 'record_version',
            ]), $reason);

            return $locked->load(['vehicle', 'driver']);
        }, 3);
    }

    public function acknowledge(VehicleDriverAssignment $assignment, User $actor, ?UserSession $session, Request $request): VehicleDriverAssignment
    {
        return DB::transaction(function () use ($assignment, $actor, $session, $request): VehicleDriverAssignment {
            /** @var VehicleDriverAssignment $locked */
            $locked = VehicleDriverAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            /** @var Driver|null $driver */
            $driver = Driver::query()->whereKey($locked->driver_id)->first();
            if ($driver?->user_id !== $actor->id) {
                throw new BusinessRuleException('ASSIGNMENT_ACKNOWLEDGEMENT_DENIED', 'Only the assigned driver may acknowledge this assignment.');
            }
            if (! $locked->acknowledgement_required || $locked->acknowledged_at !== null) {
                throw new BusinessRuleException('ASSIGNMENT_ACKNOWLEDGEMENT_INVALID', 'The assignment does not require a pending acknowledgement.');
            }
            $before = ['acknowledged_at' => null, 'record_version' => $locked->record_version];
            $locked->forceFill(['acknowledged_at' => now(), 'record_version' => $locked->record_version + 1])->save();
            $this->record('vehicle_driver_assignment.acknowledged', 'acknowledge', $locked, $actor, $session, $request, $before, [
                'acknowledged_at' => $locked->acknowledged_at,
                'record_version' => $locked->record_version,
            ]);

            return $locked->load(['vehicle', 'driver']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function assertEligibility(Vehicle $vehicle, Driver $driver, array $data): void
    {
        if ($vehicle->status !== 'active') {
            throw new BusinessRuleException('ASSIGNMENT_VEHICLE_INELIGIBLE', 'Only an active vehicle can be assigned.');
        }
        if ($driver->status !== 'active' || $driver->employment_status !== 'active' || ! in_array($driver->availability_status, ['available', 'assigned'], true)) {
            throw new BusinessRuleException('ASSIGNMENT_DRIVER_INELIGIBLE', 'The driver is not operationally eligible.');
        }
        if ($vehicle->custodian_organization_id !== $data['organization_id'] || $driver->organization_id !== $data['organization_id']) {
            throw new BusinessRuleException('ASSIGNMENT_ORGANIZATION_MISMATCH', 'Vehicle, driver, and assignment organization must match.');
        }
        $licenceCompatible = DB::table('driver_licences as licences')
            ->join('driver_licence_class_assignments as assigned', 'assigned.driver_licence_id', '=', 'licences.id')
            ->join('vehicle_class_licence_classes as compatible', 'compatible.driver_licence_class_id', '=', 'assigned.driver_licence_class_id')
            ->where('licences.driver_id', $driver->id)
            ->where('licences.status', 'verified')
            ->whereDate('licences.expires_on', '>=', today())
            ->where('compatible.vehicle_class_id', $vehicle->vehicle_class_id)
            ->where('assigned.effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('assigned.effective_to')->orWhere('assigned.effective_to', '>', now()))
            ->exists();
        if (! $licenceCompatible) {
            throw new BusinessRuleException('ASSIGNMENT_LICENCE_INCOMPATIBLE', 'The driver lacks a current verified licence compatible with the vehicle class.');
        }
        if (DB::table('driver_restrictions')->where('driver_id', $driver->id)->where('status', 'active')
            ->where('starts_at', '<=', now())->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->exists()) {
            throw new BusinessRuleException('ASSIGNMENT_DRIVER_RESTRICTED', 'An active driver restriction blocks the assignment.');
        }
        if (! $data['exclusive']) {
            return;
        }
        $end = $data['ends_at'] ?? '9999-12-31 23:59:59';
        $overlap = VehicleDriverAssignment::query()
            ->where('status', 'active')->where('exclusive', true)
            ->where(fn ($query) => $query->where('vehicle_id', $vehicle->id)->orWhere('driver_id', $driver->id))
            ->where('starts_at', '<', $end)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $data['starts_at']))
            ->exists();
        if ($overlap) {
            throw new BusinessRuleException('ASSIGNMENT_OVERLAP', 'The assignment overlaps another exclusive vehicle or driver assignment.');
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function record(
        string $event,
        string $action,
        VehicleDriverAssignment $assignment,
        User $actor,
        ?UserSession $session,
        Request $request,
        ?array $before,
        array $after,
        ?string $reason = null,
    ): void {
        $this->audit->record(
            $event.'.succeeded', 'fleet', $action, 'succeeded', 'vehicle_driver_assignment', $assignment->id,
            $assignment->organization_id, $actor, $session, $reason, $before, $after, request: $request,
        );
        $this->outbox->enqueue(
            $event, 'vehicle_driver_assignment', $assignment->id,
            ['id' => $assignment->id, 'organization_id' => $assignment->organization_id, 'record_version' => $assignment->record_version],
            $event.':'.$assignment->id.':'.$assignment->record_version,
            $assignment->organization_id,
            $request->attributes->get('correlation_id'),
            $request->attributes->get('causation_id'),
        );
    }
}
