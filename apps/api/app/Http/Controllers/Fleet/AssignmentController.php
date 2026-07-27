<?php

namespace App\Http\Controllers\Fleet;

use App\Audit\Services\AuditService;
use App\Fleet\Models\Driver;
use App\Fleet\Models\VehicleDriverAssignment;
use App\Fleet\Services\VehicleAssignmentService;
use App\Http\Requests\Fleet\StoreAssignmentRequest;
use App\Http\Resources\Fleet\AssignmentResource;
use App\Identity\Services\AuthorizationService;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AssignmentController extends FleetController
{
    public function __construct(
        AuthorizationService $authorization,
        AuditService $audit,
        OutboxService $outbox,
        private readonly VehicleAssignmentService $assignments,
    ) {
        parent::__construct($authorization, $audit, $outbox);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'vehicle_id' => ['nullable', 'string', 'size:26'],
            'driver_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', 'string', 'max:30'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeOrganization($request, 'assignment.view', $data['organization_id']);
        $query = VehicleDriverAssignment::query()->with(['vehicle', 'driver'])
            ->where('organization_id', $data['organization_id'])
            ->when($data['vehicle_id'] ?? null, fn ($builder, $value) => $builder->where('vehicle_id', $value))
            ->when($data['driver_id'] ?? null, fn ($builder, $value) => $builder->where('driver_id', $value))
            ->when($data['status'] ?? null, fn ($builder, $value) => $builder->where('status', $value))
            ->when($data['from'] ?? null, fn ($builder, $value) => $builder->where(fn ($nested) => $nested->whereNull('ends_at')->orWhere('ends_at', '>=', $value)))
            ->when($data['to'] ?? null, fn ($builder, $value) => $builder->where('starts_at', '<=', $value))
            ->orderByDesc('starts_at');

        return AssignmentResource::collection($query->paginate($data['per_page'] ?? 25));
    }

    public function store(StoreAssignmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->authorizeOrganization($request, 'assignment.create', $data['organization_id']);

        return (new AssignmentResource(
            $this->assignments->assign($data, $this->actor($request), $this->session($request), $request),
        ))->response()->setStatusCode(201);
    }

    public function close(Request $request, VehicleDriverAssignment $assignment): AssignmentResource
    {
        $data = $request->validate([
            'record_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $this->authorizeOrganization($request, 'assignment.close', $assignment->organization_id, 'vehicle_driver_assignment', $assignment->id);

        return new AssignmentResource($this->assignments->close(
            $assignment, $data['record_version'], $data['reason'], $this->actor($request), $this->session($request), $request,
        ));
    }

    public function acknowledge(Request $request, VehicleDriverAssignment $assignment): AssignmentResource
    {
        return new AssignmentResource($this->assignments->acknowledge(
            $assignment, $this->actor($request), $this->session($request), $request,
        ));
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        $driver = Driver::query()->where('user_id', $this->actor($request)->id)->firstOrFail();

        return AssignmentResource::collection(VehicleDriverAssignment::query()
            ->with(['vehicle.complianceRecords', 'driver'])
            ->where('driver_id', $driver->id)
            ->orderByDesc('starts_at')
            ->paginate(25));
    }
}
