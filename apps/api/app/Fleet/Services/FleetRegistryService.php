<?php

namespace App\Fleet\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Fleet\Models\Driver;
use App\Fleet\Models\DriverLicence;
use App\Fleet\Models\Vehicle;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FleetRegistryService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function createVehicle(array $data, User $actor, ?UserSession $session, Request $request): Vehicle
    {
        return DB::transaction(function () use ($data, $actor, $session, $request): Vehicle {
            $this->assertVehicleTaxonomy($data);
            $vehicle = Vehicle::query()->create([
                ...collect($data)->except(['plate', 'plate_number', 'compliance'])->all(),
                'current_plate_number' => $data['plate_number'] ?? null,
                'current_odometer_km' => $data['baseline_odometer_km'],
                'status' => 'draft',
            ]);
            $now = now();
            DB::table('vehicle_status_history')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $vehicle->id,
                'from_status' => null,
                'to_status' => 'draft',
                'reason' => 'Initial registry entry.',
                'changed_by' => $actor->id,
                'effective_at' => $now,
            ]);
            DB::table('vehicle_ownership_history')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $vehicle->id,
                'owning_organization_id' => $vehicle->owning_organization_id,
                'custodian_organization_id' => $vehicle->custodian_organization_id,
                'ownership_type' => $vehicle->ownership_type,
                'effective_from' => $now,
                'recorded_by' => $actor->id,
            ]);
            DB::table('vehicle_odometer_readings')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $vehicle->id,
                'reading_km' => $vehicle->baseline_odometer_km,
                'source' => 'registry_baseline',
                'recorded_at' => $now,
                'recorded_by' => $actor->id,
            ]);
            if ($vehicle->current_plate_number !== null) {
                DB::table('vehicle_plate_history')->insert([
                    'id' => (string) Str::ulid(),
                    'vehicle_id' => $vehicle->id,
                    'plate_number' => $vehicle->current_plate_number,
                    'issuing_region' => data_get($data, 'plate.issuing_region'),
                    'issued_on' => data_get($data, 'plate.issued_on'),
                    'expires_on' => data_get($data, 'plate.expires_on'),
                    'effective_from' => $now,
                    'status' => 'active',
                    'changed_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if ($vehicle->fleet_unit_id !== null) {
                DB::table('vehicle_fleet_assignments')->insert([
                    'id' => (string) Str::ulid(),
                    'vehicle_id' => $vehicle->id,
                    'fleet_unit_id' => $vehicle->fleet_unit_id,
                    'starts_at' => $now,
                    'assigned_by' => $actor->id,
                    'status' => 'active',
                ]);
            }
            foreach ($data['compliance'] ?? [] as $record) {
                $this->createComplianceRecord('vehicle', $vehicle->id, $vehicle->custodian_organization_id, $record);
            }
            $this->recordChange('vehicle.created', 'create', $vehicle, $actor, $session, $request, null, $vehicle->toArray());

            return $vehicle->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createDriver(array $data, User $actor, ?UserSession $session, Request $request): Driver
    {
        return DB::transaction(function () use ($data, $actor, $session, $request): Driver {
            $licenceData = $data['licence'];
            $driver = Driver::query()->create([
                ...collect($data)->except(['licence', 'qualifications', 'restrictions', 'phone', 'email', 'emergency_contact'])->all(),
                'phone_encrypted' => $data['phone'] ?? null,
                'email_encrypted' => $data['email'] ?? null,
                'emergency_contact_encrypted' => $data['emergency_contact'] ?? null,
            ]);
            $now = now();
            DB::table('driver_status_history')->insert([
                'id' => (string) Str::ulid(),
                'driver_id' => $driver->id,
                'from_status' => null,
                'to_status' => $driver->status,
                'availability_status' => $driver->availability_status,
                'reason' => 'Initial driver registry entry.',
                'changed_by' => $actor->id,
                'effective_at' => $now,
            ]);
            $licence = DriverLicence::query()->create([
                'driver_id' => $driver->id,
                'licence_number_encrypted' => $licenceData['number'],
                'licence_number_hash' => $this->lookupHash($licenceData['number']),
                'issuing_authority' => $licenceData['issuing_authority'],
                'issued_on' => $licenceData['issued_on'] ?? null,
                'expires_on' => $licenceData['expires_on'],
                'status' => $licenceData['status'],
                'document_id' => $licenceData['document_id'] ?? null,
                'verified_at' => $licenceData['status'] === 'verified' ? $now : null,
                'verified_by' => $licenceData['status'] === 'verified' ? $actor->id : null,
            ]);
            foreach ($licenceData['class_ids'] as $classId) {
                DB::table('driver_licence_class_assignments')->insert([
                    'id' => (string) Str::ulid(),
                    'driver_licence_id' => $licence->id,
                    'driver_licence_class_id' => $classId,
                    'effective_from' => $now,
                ]);
            }
            foreach ($data['qualifications'] ?? [] as $qualification) {
                DB::table('driver_qualifications')->insert([
                    'id' => (string) Str::ulid(),
                    'driver_id' => $driver->id,
                    ...$qualification,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            foreach ($data['restrictions'] ?? [] as $restriction) {
                DB::table('driver_restrictions')->insert([
                    'id' => (string) Str::ulid(),
                    'driver_id' => $driver->id,
                    ...$restriction,
                    'status' => 'active',
                    'imposed_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->recordChange('driver.created', 'create', $driver, $actor, $session, $request, null, [
                'employee_number' => $driver->employee_number,
                'organization_id' => $driver->organization_id,
                'status' => $driver->status,
                'licence_id' => $licence->id,
            ]);

            return $driver->refresh();
        }, 3);
    }

    public function transitionVehicle(
        Vehicle $vehicle,
        string $status,
        ?string $reason,
        int $recordVersion,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): Vehicle {
        return DB::transaction(function () use ($vehicle, $status, $reason, $recordVersion, $actor, $session, $request): Vehicle {
            /** @var Vehicle $locked */
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($locked->record_version !== $recordVersion) {
                throw new ConflictException('FLEET_RECORD_VERSION_CONFLICT', 'The vehicle was changed by another operation.');
            }
            $allowed = [
                'draft' => ['active', 'retired'],
                'active' => ['suspended', 'under_maintenance', 'out_of_service', 'retired'],
                'suspended' => ['active', 'out_of_service', 'retired'],
                'under_maintenance' => ['active', 'out_of_service', 'retired'],
                'out_of_service' => ['active', 'retired'],
                'retired' => [],
            ];
            if (! in_array($status, $allowed[$locked->status] ?? [], true)) {
                throw new BusinessRuleException('VEHICLE_STATUS_TRANSITION_INVALID', 'The requested vehicle status transition is not allowed.');
            }
            if ($status === 'active') {
                $this->assertVehicleActivationReady($locked);
            }
            if ($status === 'retired' && DB::table('vehicle_driver_assignments')->where('vehicle_id', $locked->id)->where('status', 'active')->exists()) {
                throw new BusinessRuleException('VEHICLE_RETIREMENT_ASSIGNMENT_OPEN', 'Close active driver assignments before retirement.');
            }
            $before = ['status' => $locked->status, 'record_version' => $locked->record_version];
            $locked->forceFill([
                'status' => $status,
                'retired_at' => $status === 'retired' ? now() : null,
                'record_version' => $locked->record_version + 1,
            ])->save();
            DB::table('vehicle_status_history')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $locked->id,
                'from_status' => $before['status'],
                'to_status' => $status,
                'reason' => $reason,
                'changed_by' => $actor->id,
                'effective_at' => now(),
            ]);
            $this->recordChange('vehicle.status.changed', 'transition', $locked, $actor, $session, $request, $before, [
                'status' => $status,
                'record_version' => $locked->record_version,
            ], $reason);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function transferVehicle(Vehicle $vehicle, array $data, User $actor, ?UserSession $session, Request $request): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $session, $request): Vehicle {
            /** @var Vehicle $locked */
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'retired') {
                throw new BusinessRuleException('RETIRED_VEHICLE_IMMUTABLE', 'A retired vehicle cannot receive a new operational ownership assignment.');
            }
            if ($locked->record_version !== $data['record_version']) {
                throw new ConflictException('FLEET_RECORD_VERSION_CONFLICT', 'The vehicle was changed by another operation.');
            }
            $before = $locked->only(['owning_organization_id', 'custodian_organization_id', 'ownership_type', 'record_version']);
            DB::table('vehicle_ownership_history')->where('vehicle_id', $locked->id)->whereNull('effective_to')->update(['effective_to' => now()]);
            DB::table('vehicle_ownership_history')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $locked->id,
                'owning_organization_id' => $data['owning_organization_id'],
                'custodian_organization_id' => $data['custodian_organization_id'],
                'ownership_type' => $data['ownership_type'],
                'transfer_reference' => $data['transfer_reference'],
                'reason' => $data['reason'],
                'effective_from' => now(),
                'recorded_by' => $actor->id,
            ]);
            $locked->forceFill([
                'owning_organization_id' => $data['owning_organization_id'],
                'custodian_organization_id' => $data['custodian_organization_id'],
                'ownership_type' => $data['ownership_type'],
                'record_version' => $locked->record_version + 1,
            ])->save();
            $this->recordChange('vehicle.organization.transferred', 'transfer', $locked, $actor, $session, $request, $before, $locked->only([
                'owning_organization_id', 'custodian_organization_id', 'ownership_type', 'record_version',
            ]), $data['reason']);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function recordOdometer(Vehicle $vehicle, array $data, User $actor, ?UserSession $session, Request $request): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $session, $request): Vehicle {
            /** @var Vehicle $locked */
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ((float) $data['reading_km'] < (float) $locked->current_odometer_km) {
                throw new BusinessRuleException('ODOMETER_ROLLBACK_REJECTED', 'The reading cannot be lower than the current odometer.');
            }
            DB::table('vehicle_odometer_readings')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $locked->id,
                'reading_km' => $data['reading_km'],
                'source' => $data['source'],
                'recorded_at' => $data['recorded_at'],
                'recorded_by' => $actor->id,
                'document_id' => $data['document_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $before = ['current_odometer_km' => $locked->current_odometer_km];
            $locked->forceFill([
                'current_odometer_km' => $data['reading_km'],
                'record_version' => $locked->record_version + 1,
            ])->save();
            $this->recordChange('vehicle.odometer.recorded', 'record', $locked, $actor, $session, $request, $before, [
                'current_odometer_km' => $locked->current_odometer_km,
            ]);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function changePlate(Vehicle $vehicle, array $data, User $actor, ?UserSession $session, Request $request): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $session, $request): Vehicle {
            /** @var Vehicle $locked */
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'retired') {
                throw new BusinessRuleException('RETIRED_VEHICLE_IMMUTABLE', 'A retired vehicle cannot receive a new plate.');
            }
            if ($locked->record_version !== $data['record_version']) {
                throw new ConflictException('FLEET_RECORD_VERSION_CONFLICT', 'The vehicle was changed by another operation.');
            }
            $plate = mb_strtoupper(trim($data['plate_number']));
            if (Vehicle::query()->where('current_plate_number', $plate)->whereKeyNot($locked->id)->exists()) {
                throw new BusinessRuleException('VEHICLE_PLATE_DUPLICATE', 'The plate is already assigned to another current vehicle.');
            }
            $before = ['current_plate_number' => $locked->current_plate_number, 'record_version' => $locked->record_version];
            DB::table('vehicle_plate_history')->where('vehicle_id', $locked->id)->whereNull('effective_to')->update([
                'effective_to' => now(), 'status' => 'replaced', 'updated_at' => now(),
            ]);
            DB::table('vehicle_plate_history')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $locked->id,
                'plate_number' => $plate,
                'issuing_region' => $data['issuing_region'] ?? null,
                'issued_on' => $data['issued_on'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'effective_from' => now(),
                'status' => 'active',
                'change_reason' => $data['reason'],
                'changed_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $locked->forceFill(['current_plate_number' => $plate, 'record_version' => $locked->record_version + 1])->save();
            $this->recordChange('vehicle.plate.changed', 'change_plate', $locked, $actor, $session, $request, $before, [
                'current_plate_number' => $plate, 'record_version' => $locked->record_version,
            ], $data['reason']);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function assignFleetUnit(Vehicle $vehicle, array $data, User $actor, ?UserSession $session, Request $request): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $session, $request): Vehicle {
            /** @var Vehicle $locked */
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'retired') {
                throw new BusinessRuleException('RETIRED_VEHICLE_IMMUTABLE', 'A retired vehicle cannot receive a new fleet assignment.');
            }
            if ($locked->record_version !== $data['record_version']) {
                throw new ConflictException('FLEET_RECORD_VERSION_CONFLICT', 'The vehicle was changed by another operation.');
            }
            $unitMatches = DB::table('fleet_units')->where('id', $data['fleet_unit_id'])->where('organization_id', $locked->custodian_organization_id)->where('status', 'active')->exists();
            if (! $unitMatches) {
                throw new BusinessRuleException('FLEET_UNIT_ORGANIZATION_MISMATCH', 'The fleet unit must be active and belong to the vehicle custodian organization.');
            }
            $before = ['fleet_unit_id' => $locked->fleet_unit_id, 'record_version' => $locked->record_version];
            DB::table('vehicle_fleet_assignments')->where('vehicle_id', $locked->id)->where('status', 'active')->update([
                'status' => 'closed', 'ends_at' => now(),
            ]);
            DB::table('vehicle_fleet_assignments')->insert([
                'id' => (string) Str::ulid(),
                'vehicle_id' => $locked->id,
                'fleet_unit_id' => $data['fleet_unit_id'],
                'starts_at' => $data['starts_at'] ?? now(),
                'reason' => $data['reason'],
                'assigned_by' => $actor->id,
                'status' => 'active',
            ]);
            $locked->forceFill(['fleet_unit_id' => $data['fleet_unit_id'], 'record_version' => $locked->record_version + 1])->save();
            $this->recordChange('vehicle.fleet.assigned', 'assign_fleet', $locked, $actor, $session, $request, $before, [
                'fleet_unit_id' => $locked->fleet_unit_id, 'record_version' => $locked->record_version,
            ], $data['reason']);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function renewCompliance(Vehicle $vehicle, array $data, User $actor, ?UserSession $session, Request $request): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $actor, $session, $request): Vehicle {
            /** @var Vehicle $locked */
            $locked = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            $previous = DB::table('fleet_compliance_records')->where('entity_type', 'vehicle')->where('entity_id', $locked->id)
                ->where('document_type', $data['document_type'])->where('status', 'current')->lockForUpdate()->first();
            if ($previous !== null) {
                DB::table('fleet_compliance_records')->where('id', $previous->id)->update(['status' => 'superseded', 'updated_at' => now()]);
                $data['supersedes_record_id'] = $previous->id;
            }
            $this->createComplianceRecord('vehicle', $locked->id, $locked->custodian_organization_id, $data);
            $before = ['record_version' => $locked->record_version, 'document_type' => $data['document_type']];
            $locked->forceFill(['record_version' => $locked->record_version + 1])->save();
            $this->recordChange('vehicle.compliance.renewed', 'renew_compliance', $locked, $actor, $session, $request, $before, [
                'record_version' => $locked->record_version,
                'document_type' => $data['document_type'],
                'expires_on' => $data['expires_on'] ?? null,
            ]);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function renewDriverLicence(Driver $driver, array $data, User $actor, ?UserSession $session, Request $request): Driver
    {
        return DB::transaction(function () use ($driver, $data, $actor, $session, $request): Driver {
            /** @var Driver $locked */
            $locked = Driver::query()->whereKey($driver->id)->lockForUpdate()->firstOrFail();
            $previous = DriverLicence::query()->where('driver_id', $locked->id)->whereIn('status', ['verified', 'pending_verification'])
                ->latest('created_at')->lockForUpdate()->first();
            if ($previous !== null) {
                $previous->forceFill(['status' => 'superseded'])->save();
            }
            $licence = DriverLicence::query()->create([
                'driver_id' => $locked->id,
                'licence_number_encrypted' => $data['number'],
                'licence_number_hash' => $this->lookupHash($data['number']),
                'issuing_authority' => $data['issuing_authority'],
                'issued_on' => $data['issued_on'] ?? null,
                'expires_on' => $data['expires_on'],
                'status' => $data['status'],
                'supersedes_licence_id' => $previous?->id,
                'document_id' => $data['document_id'] ?? null,
                'verified_at' => $data['status'] === 'verified' ? now() : null,
                'verified_by' => $data['status'] === 'verified' ? $actor->id : null,
            ]);
            foreach ($data['class_ids'] as $classId) {
                DB::table('driver_licence_class_assignments')->insert([
                    'id' => (string) Str::ulid(),
                    'driver_licence_id' => $licence->id,
                    'driver_licence_class_id' => $classId,
                    'effective_from' => now(),
                ]);
            }
            $before = ['record_version' => $locked->record_version, 'licence_id' => $previous?->id];
            $locked->forceFill(['record_version' => $locked->record_version + 1])->save();
            $this->recordChange('driver.licence.renewed', 'renew_licence', $locked, $actor, $session, $request, $before, [
                'record_version' => $locked->record_version,
                'licence_id' => $licence->id,
                'status' => $licence->status,
                'expires_on' => $licence->expires_on->toDateString(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function transitionDriver(Driver $driver, array $data, User $actor, ?UserSession $session, Request $request): Driver
    {
        return DB::transaction(function () use ($driver, $data, $actor, $session, $request): Driver {
            /** @var Driver $locked */
            $locked = Driver::query()->whereKey($driver->id)->lockForUpdate()->firstOrFail();
            if ($locked->record_version !== $data['record_version']) {
                throw new ConflictException('FLEET_RECORD_VERSION_CONFLICT', 'The driver was changed by another operation.');
            }
            if ($data['status'] !== 'active' && DB::table('vehicle_driver_assignments')->where('driver_id', $locked->id)->where('status', 'active')->exists()) {
                throw new BusinessRuleException('DRIVER_STATUS_ASSIGNMENT_OPEN', 'Close active assignments before making the driver operationally unavailable.');
            }
            $before = $locked->only(['status', 'availability_status', 'record_version']);
            $locked->forceFill([
                'status' => $data['status'],
                'availability_status' => $data['availability_status'],
                'record_version' => $locked->record_version + 1,
                'terminated_on' => $data['status'] === 'terminated' ? today() : $locked->terminated_on,
            ])->save();
            DB::table('driver_status_history')->insert([
                'id' => (string) Str::ulid(),
                'driver_id' => $locked->id,
                'from_status' => $before['status'],
                'to_status' => $locked->status,
                'availability_status' => $locked->availability_status,
                'reason' => $data['reason'],
                'changed_by' => $actor->id,
                'effective_at' => now(),
            ]);
            $this->recordChange('driver.status.changed', 'transition', $locked, $actor, $session, $request, $before, $locked->only([
                'status', 'availability_status', 'record_version',
            ]), $data['reason']);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    private function assertVehicleTaxonomy(array $data): void
    {
        $classMatches = DB::table('vehicle_classes')->where('id', $data['vehicle_class_id'])
            ->where('vehicle_category_id', $data['vehicle_category_id'])->exists();
        $modelMatches = DB::table('vehicle_models')->where('id', $data['vehicle_model_id'])
            ->where('manufacturer_id', $data['manufacturer_id'])->exists();
        $trimMatches = ! isset($data['vehicle_trim_id']) || DB::table('vehicle_trims')
            ->where('id', $data['vehicle_trim_id'])->where('vehicle_model_id', $data['vehicle_model_id'])->exists();
        if (! $classMatches || ! $modelMatches || ! $trimMatches) {
            throw new BusinessRuleException('VEHICLE_TAXONOMY_MISMATCH', 'Vehicle category, class, manufacturer, model, and trim must form a valid hierarchy.');
        }
    }

    private function assertVehicleActivationReady(Vehicle $vehicle): void
    {
        if ($vehicle->current_plate_number === null) {
            throw new BusinessRuleException('VEHICLE_ACTIVATION_PLATE_REQUIRED', 'An active plate is required before vehicle activation.');
        }
        $currentTypes = DB::table('fleet_compliance_records')
            ->where('entity_type', 'vehicle')->where('entity_id', $vehicle->id)
            ->where('status', 'current')
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', today()))
            ->pluck('document_type');
        foreach (['insurance', 'registration', 'roadworthiness'] as $required) {
            if (! $currentTypes->contains($required)) {
                throw new BusinessRuleException('VEHICLE_ACTIVATION_COMPLIANCE_REQUIRED', 'Current insurance, registration, and roadworthiness records are required before activation.');
            }
        }
    }

    /** @param array<string, mixed> $record */
    private function createComplianceRecord(string $entityType, string $entityId, string $organizationId, array $record): void
    {
        $number = $record['document_number'] ?? null;
        DB::table('fleet_compliance_records')->insert([
            'id' => (string) Str::ulid(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'organization_id' => $organizationId,
            'document_type' => $record['document_type'],
            'document_number_encrypted' => $number === null ? null : Crypt::encryptString($number),
            'document_number_hash' => $number === null ? null : $this->lookupHash($number),
            'issued_on' => $record['issued_on'] ?? null,
            'expires_on' => $record['expires_on'] ?? null,
            'document_id' => $record['document_id'] ?? null,
            'status' => 'current',
            'supersedes_record_id' => $record['supersedes_record_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lookupHash(string $value): string
    {
        return hash_hmac('sha256', mb_strtoupper(trim($value)), (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function recordChange(
        string $event,
        string $action,
        Vehicle|Driver $subject,
        User $actor,
        ?UserSession $session,
        Request $request,
        ?array $before,
        array $after,
        ?string $reason = null,
    ): void {
        $organizationId = $subject instanceof Vehicle ? $subject->custodian_organization_id : $subject->organization_id;
        $type = $subject instanceof Vehicle ? 'vehicle' : 'driver';
        $this->audit->record(
            $event.'.succeeded', 'fleet', $action, 'succeeded', $type, $subject->id,
            $organizationId, $actor, $session, $reason, $before, $after,
            request: $request,
        );
        $this->outbox->enqueue(
            $event, $type, $subject->id,
            ['id' => $subject->id, 'organization_id' => $organizationId, 'record_version' => $subject->record_version],
            $event.':'.$subject->id.':'.$subject->record_version,
            $organizationId,
            $request->attributes->get('correlation_id'),
            $request->attributes->get('causation_id'),
        );
    }
}
