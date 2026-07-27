<?php

namespace App\Http\Controllers\Fleet;

use App\Audit\Services\AuditService;
use App\Fleet\Models\Vehicle;
use App\Fleet\Services\FleetRegistryService;
use App\Http\Requests\Fleet\StoreVehicleRequest;
use App\Http\Requests\Fleet\UpdateVehicleStatusRequest;
use App\Http\Resources\Fleet\VehicleResource;
use App\Identity\Services\AuthorizationService;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class VehicleController extends FleetController
{
    public function __construct(
        AuthorizationService $authorization,
        AuditService $audit,
        OutboxService $outbox,
        private readonly FleetRegistryService $registry,
    ) {
        parent::__construct($authorization, $audit, $outbox);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'query' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:30'],
            'vehicle_class_id' => ['nullable', 'string', 'size:26'],
            'fleet_unit_id' => ['nullable', 'string', 'size:26'],
            'ownership_type' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeOrganization($request, 'vehicle.view', $data['organization_id']);
        $query = Vehicle::query()->with(['vehicleClass', 'manufacturer', 'vehicleModel'])
            ->where('custodian_organization_id', $data['organization_id'])
            ->when($data['query'] ?? null, function ($builder, $value): void {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
                $prefix = "{$escaped}%";
                $builder->where(fn ($nested) => $nested
                    ->where('asset_number', 'like', $prefix)
                    ->orWhere('current_plate_number', 'like', $prefix)
                    ->orWhere('vin', 'like', $prefix)
                    ->orWhere('chassis_number', 'like', $prefix));
            })
            ->when($data['status'] ?? null, fn ($builder, $value) => $builder->where('status', $value))
            ->when($data['vehicle_class_id'] ?? null, fn ($builder, $value) => $builder->where('vehicle_class_id', $value))
            ->when($data['fleet_unit_id'] ?? null, fn ($builder, $value) => $builder->where('fleet_unit_id', $value))
            ->when($data['ownership_type'] ?? null, fn ($builder, $value) => $builder->where('ownership_type', $value))
            ->orderBy('asset_number');

        return VehicleResource::collection($query->paginate($data['per_page'] ?? 25));
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->authorizeOrganization($request, 'vehicle.create', $data['custodian_organization_id']);

        return (new VehicleResource(
            $this->registry->createVehicle($data, $this->actor($request), $this->session($request), $request),
        ))->response()->setStatusCode(201);
    }

    public function show(Request $request, Vehicle $vehicle): VehicleResource
    {
        $this->authorizeOrganization($request, 'vehicle.view', $vehicle->custodian_organization_id, 'vehicle', $vehicle->id);
        $vehicle->load(['vehicleClass', 'manufacturer', 'vehicleModel', 'assignments.driver']);

        return (new VehicleResource($vehicle))->additional(['history' => [
            'statuses' => \DB::table('vehicle_status_history')->where('vehicle_id', $vehicle->id)->orderByDesc('effective_at')->get(),
            'plates' => \DB::table('vehicle_plate_history')->where('vehicle_id', $vehicle->id)->orderByDesc('effective_from')->get(),
            'ownership' => \DB::table('vehicle_ownership_history')->where('vehicle_id', $vehicle->id)->orderByDesc('effective_from')->get(),
            'odometer' => \DB::table('vehicle_odometer_readings')->where('vehicle_id', $vehicle->id)->orderByDesc('recorded_at')->limit(100)->get(),
            'compliance' => \DB::table('fleet_compliance_records')->where('entity_type', 'vehicle')->where('entity_id', $vehicle->id)
                ->select(['id', 'document_type', 'issued_on', 'expires_on', 'document_id', 'status', 'supersedes_record_id'])->orderByDesc('created_at')->get(),
            'documents' => $this->documentSummaries('vehicle', $vehicle->id, $vehicle->custodian_organization_id),
        ]]);
    }

    public function status(UpdateVehicleStatusRequest $request, Vehicle $vehicle): VehicleResource
    {
        $this->authorizeOrganization($request, 'vehicle.status.manage', $vehicle->custodian_organization_id, 'vehicle', $vehicle->id);
        $data = $request->validated();

        return new VehicleResource($this->registry->transitionVehicle(
            $vehicle, $data['status'], $data['reason'] ?? null, $data['record_version'],
            $this->actor($request), $this->session($request), $request,
        ));
    }

    public function transfer(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate([
            'owning_organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'custodian_organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'ownership_type' => ['required', Rule::in(['owned', 'leased', 'donated', 'transferred', 'other'])],
            'transfer_reference' => ['required', 'string', 'max:190'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->authorizeOrganization($request, 'vehicle.transfer', $vehicle->custodian_organization_id, 'vehicle', $vehicle->id);
        $this->authorizeOrganization($request, 'vehicle.transfer.receive', $data['custodian_organization_id']);

        return new VehicleResource($this->registry->transferVehicle($vehicle, $data, $this->actor($request), $this->session($request), $request));
    }

    public function odometer(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate([
            'reading_km' => ['required', 'numeric', 'min:0'],
            'source' => ['required', Rule::in(['manual_verified', 'inspection', 'handover', 'import'])],
            'recorded_at' => ['required', 'date', 'before_or_equal:now'],
            'document_id' => ['nullable', 'string', 'size:26', 'exists:documents,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $this->authorizeOrganization($request, 'vehicle.odometer.record', $vehicle->custodian_organization_id, 'vehicle', $vehicle->id);

        return new VehicleResource($this->registry->recordOdometer($vehicle, $data, $this->actor($request), $this->session($request), $request));
    }

    public function plate(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate([
            'plate_number' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[\pL\pN .-]+$/u'],
            'issuing_region' => ['nullable', 'string', 'max:100'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:issued_on'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->authorizeOrganization($request, 'vehicle.plate.manage', $vehicle->custodian_organization_id, 'vehicle', $vehicle->id);

        return new VehicleResource($this->registry->changePlate($vehicle, $data, $this->actor($request), $this->session($request), $request));
    }

    public function fleetUnit(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate([
            'fleet_unit_id' => ['required', 'string', 'size:26', 'exists:fleet_units,id'],
            'starts_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->authorizeOrganization($request, 'vehicle.fleet.assign', $vehicle->custodian_organization_id, 'vehicle', $vehicle->id);

        return new VehicleResource($this->registry->assignFleetUnit($vehicle, $data, $this->actor($request), $this->session($request), $request));
    }

    public function compliance(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(['insurance', 'registration', 'roadworthiness', 'road_use', 'other'])],
            'document_number' => ['nullable', 'string', 'max:190'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'document_id' => ['nullable', 'string', 'size:26', 'exists:documents,id'],
        ]);
        $this->authorizeOrganization($request, 'vehicle.compliance.manage', $vehicle->custodian_organization_id, 'vehicle', $vehicle->id);

        return new VehicleResource($this->registry->renewCompliance($vehicle, $data, $this->actor($request), $this->session($request), $request));
    }
}
