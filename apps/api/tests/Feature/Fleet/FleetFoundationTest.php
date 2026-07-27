<?php

namespace Tests\Feature\Fleet;

use App\Exceptions\BusinessRuleException;
use App\Fleet\Services\FleetRegistryService;
use App\Fleet\Services\VehicleAssignmentService;
use App\Identity\Models\User;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FleetFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vehicle_lifecycle_preserves_compliance_odometer_ownership_audit_and_outbox_history(): void
    {
        [$organization, $actor] = $this->context();
        $taxonomy = $this->taxonomy();
        $registry = app(FleetRegistryService::class);
        $request = Request::create('/api/v1/vehicles', 'POST');
        $vehicle = $registry->createVehicle($this->vehicleData($organization, $taxonomy), $actor, null, $request);

        $this->assertSame('draft', $vehicle->status);
        $this->assertDatabaseCount('vehicle_plate_history', 1);
        $this->assertDatabaseCount('vehicle_odometer_readings', 1);
        $this->assertDatabaseCount('vehicle_ownership_history', 1);
        $this->assertDatabaseCount('fleet_compliance_records', 3);
        $this->assertDatabaseHas('audit_events', ['subject_type' => 'vehicle', 'subject_id' => $vehicle->id]);
        $this->assertDatabaseHas('outbox_messages', ['topic' => 'vehicle.created', 'aggregate_id' => $vehicle->id]);

        $vehicle = $registry->transitionVehicle($vehicle, 'active', null, 1, $actor, null, $request);
        $this->assertSame('active', $vehicle->status);
        $this->assertSame(2, $vehicle->record_version);

        $vehicle = $registry->recordOdometer($vehicle, [
            'reading_km' => 1250.5,
            'source' => 'manual_verified',
            'recorded_at' => now(),
            'notes' => 'Verified during controlled handover.',
        ], $actor, null, $request);
        $this->assertSame('1250.5', $vehicle->current_odometer_km);

        $other = $this->organization('ORG_TRANSFER');
        $vehicle = $registry->transferVehicle($vehicle, [
            'owning_organization_id' => $other->id,
            'custodian_organization_id' => $other->id,
            'ownership_type' => 'transferred',
            'transfer_reference' => 'TRF-2026-0001',
            'reason' => 'Approved inter-organization transfer.',
            'record_version' => 3,
        ], $actor, null, $request);
        $this->assertSame($other->id, $vehicle->custodian_organization_id);
        $this->assertDatabaseCount('vehicle_ownership_history', 2);
        $this->assertNotNull(DB::table('vehicle_ownership_history')->where('vehicle_id', $vehicle->id)->oldest('effective_from')->value('effective_to'));

        $this->expectException(BusinessRuleException::class);
        $registry->recordOdometer($vehicle, [
            'reading_km' => 1200,
            'source' => 'manual_verified',
            'recorded_at' => now(),
        ], $actor, null, $request);
    }

    public function test_assignment_requires_verified_compatible_licence_blocks_overlap_and_supports_driver_acknowledgement(): void
    {
        [$organization, $driverUser] = $this->context();
        $taxonomy = $this->taxonomy();
        $registry = app(FleetRegistryService::class);
        $request = Request::create('/api/v1/fleet', 'POST');
        $vehicle = $registry->createVehicle($this->vehicleData($organization, $taxonomy), $driverUser, null, $request);
        $vehicle = $registry->transitionVehicle($vehicle, 'active', null, 1, $driverUser, null, $request);
        $driver = $registry->createDriver([
            'user_id' => $driverUser->id,
            'employee_number' => 'DRV-0001',
            'organization_id' => $organization->id,
            'full_name' => 'Fleet Test Driver',
            'phone' => '+251911000001',
            'email' => 'driver@example.test',
            'emergency_contact' => 'Protected emergency contact',
            'employment_status' => 'active',
            'status' => 'active',
            'availability_status' => 'available',
            'hired_on' => today()->subYear()->toDateString(),
            'licence' => [
                'number' => 'LIC-SECRET-0001',
                'issuing_authority' => 'Authorized transport authority',
                'issued_on' => today()->subYear()->toDateString(),
                'expires_on' => today()->addYear()->toDateString(),
                'status' => 'verified',
                'class_ids' => [$taxonomy['licence_class']],
            ],
        ], $driverUser, null, $request);
        $rawLicence = (string) DB::table('driver_licences')->where('driver_id', $driver->id)->value('licence_number_encrypted');
        $this->assertStringNotContainsString('LIC-SECRET-0001', $rawLicence);
        $this->assertStringNotContainsString('+251911000001', (string) DB::table('drivers')->where('id', $driver->id)->value('phone_encrypted'));

        $assignmentData = [
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'organization_id' => $organization->id,
            'assignment_type' => 'permanent',
            'exclusive' => true,
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'reason' => 'Approved primary custodianship.',
            'handover_odometer_km' => 1200,
            'handover_fuel_level' => 'half',
            'keys_handed_over' => true,
            'documents_handed_over' => true,
            'condition_notes' => 'No visible defects at handover.',
            'acknowledgement_required' => true,
        ];
        $service = app(VehicleAssignmentService::class);
        $restrictionId = (string) Str::ulid();
        DB::table('driver_restrictions')->insert([
            'id' => $restrictionId,
            'driver_id' => $driver->id,
            'code' => 'SAFETY_REVIEW',
            'description' => 'Temporary safety review restriction.',
            'starts_at' => now()->subMinute(),
            'ends_at' => null,
            'status' => 'active',
            'imposed_by' => $driverUser->id,
            'reason' => 'Test eligibility control.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            $service->assign($assignmentData, $driverUser, null, $request);
            $this->fail('An actively restricted driver should be rejected.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('ASSIGNMENT_DRIVER_RESTRICTED', $exception->errorCode);
        }
        DB::table('driver_restrictions')->where('id', $restrictionId)->update(['status' => 'inactive']);

        $assignment = $service->assign($assignmentData, $driverUser, null, $request);
        $this->assertSame('assigned', $driver->refresh()->availability_status);
        $this->assertNull($assignment->acknowledged_at);

        try {
            $service->assign($assignmentData, $driverUser, null, $request);
            $this->fail('An overlapping exclusive assignment should be rejected.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('ASSIGNMENT_OVERLAP', $exception->errorCode);
        }

        try {
            $service->acknowledge($assignment, User::factory()->create(), null, $request);
            $this->fail('A different user should not acknowledge the assignment.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('ASSIGNMENT_ACKNOWLEDGEMENT_DENIED', $exception->errorCode);
        }
        $assignment = $service->acknowledge($assignment, $driverUser, null, $request);
        $this->assertNotNull($assignment->acknowledged_at);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'vehicle_driver_assignment.acknowledged.succeeded']);

        try {
            $registry->transitionVehicle($vehicle->refresh(), 'retired', 'End of approved service life.', 2, $driverUser, null, $request);
            $this->fail('A vehicle with an open assignment should not retire.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('VEHICLE_RETIREMENT_ASSIGNMENT_OPEN', $exception->errorCode);
        }
        $service->close($assignment, 2, 'Controlled handover completed.', $driverUser, null, $request);
        $retired = $registry->transitionVehicle($vehicle->refresh(), 'retired', 'End of approved service life.', 2, $driverUser, null, $request);
        $this->assertSame('retired', $retired->status);
        $this->assertSame('available', $driver->refresh()->availability_status);
    }

    /** @return array{Organization, User} */
    private function context(): array
    {
        return [$this->organization('ORG_FLEET'), User::factory()->create()];
    }

    private function organization(string $code): Organization
    {
        $type = OrganizationType::query()->create([
            'code' => $code.'_TYPE_'.Str::random(4),
            'name_key' => 'test.organization',
            'translations' => ['en' => 'Test organization'],
            'description' => 'Test-only organization type',
            'may_be_root' => true,
            'status' => 'active',
            'configuration_status' => 'approved',
            'effective_from' => now()->subDay(),
        ]);

        return Organization::query()->create([
            'type_id' => $type->id,
            'code' => $code.'_'.Str::random(4),
            'name' => ['en' => 'Fleet test organization'],
            'status' => 'active',
            'effective_from' => now()->subDay(),
        ]);
    }

    /** @return array{category:string,class:string,manufacturer:string,model:string,licence_class:string} */
    private function taxonomy(): array
    {
        $category = (string) Str::ulid();
        $class = (string) Str::ulid();
        $manufacturer = (string) Str::ulid();
        $model = (string) Str::ulid();
        $licenceClass = (string) Str::ulid();
        DB::table('vehicle_categories')->insert(['id' => $category, 'code' => 'TEST_PASSENGER', 'name' => json_encode(['en' => 'Passenger']), 'status' => 'active']);
        DB::table('vehicle_classes')->insert(['id' => $class, 'vehicle_category_id' => $category, 'code' => 'TEST_LIGHT', 'name' => json_encode(['en' => 'Light']), 'status' => 'active']);
        DB::table('vehicle_manufacturers')->insert(['id' => $manufacturer, 'code' => 'TEST_MAKE', 'name' => 'Test Make', 'status' => 'active']);
        DB::table('vehicle_models')->insert(['id' => $model, 'manufacturer_id' => $manufacturer, 'code' => 'TEST_MODEL', 'name' => 'Test Model', 'status' => 'active']);
        DB::table('driver_licence_classes')->insert([
            'id' => $licenceClass,
            'code' => 'TEST_B',
            'name' => json_encode(['en' => 'Class B']),
            'status' => 'active',
            'effective_from' => now()->subDay(),
        ]);
        DB::table('vehicle_class_licence_classes')->insert(['vehicle_class_id' => $class, 'driver_licence_class_id' => $licenceClass]);

        return compact('category', 'class', 'manufacturer', 'model', 'licenceClass') + ['licence_class' => $licenceClass];
    }

    /** @param array{category:string,class:string,manufacturer:string,model:string,licence_class:string} $taxonomy
     * @return array<string, mixed>
     */
    private function vehicleData(Organization $organization, array $taxonomy): array
    {
        return [
            'asset_number' => 'AST-0001',
            'vin' => '1HGCM82633A004352',
            'chassis_number' => 'CHASSIS-0001',
            'engine_number' => 'ENGINE-0001',
            'plate_number' => 'OR-2-00001',
            'registration_number' => 'REG-0001',
            'vehicle_category_id' => $taxonomy['category'],
            'vehicle_class_id' => $taxonomy['class'],
            'manufacturer_id' => $taxonomy['manufacturer'],
            'vehicle_model_id' => $taxonomy['model'],
            'owning_organization_id' => $organization->id,
            'custodian_organization_id' => $organization->id,
            'ownership_type' => 'owned',
            'model_year' => 2025,
            'color' => 'White',
            'fuel_type' => 'diesel',
            'transmission' => 'manual',
            'capacity_kg' => 750,
            'seating_capacity' => 5,
            'acquisition_method' => 'purchase',
            'purchase_date' => today()->subYear()->toDateString(),
            'purchase_value' => 2500000,
            'commissioned_on' => today()->subYear()->toDateString(),
            'baseline_odometer_km' => 1200,
            'plate' => ['issuing_region' => 'Oromia', 'issued_on' => today()->subYear()->toDateString()],
            'compliance' => collect(['insurance', 'registration', 'roadworthiness'])->map(fn ($type) => [
                'document_type' => $type,
                'document_number' => strtoupper($type).'-0001',
                'issued_on' => today()->subMonth()->toDateString(),
                'expires_on' => today()->addYear()->toDateString(),
            ])->all(),
        ];
    }
}
