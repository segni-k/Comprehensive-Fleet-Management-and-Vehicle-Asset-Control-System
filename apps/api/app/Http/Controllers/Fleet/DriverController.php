<?php

namespace App\Http\Controllers\Fleet;

use App\Audit\Services\AuditService;
use App\Fleet\Models\Driver;
use App\Fleet\Services\FleetRegistryService;
use App\Http\Requests\Fleet\StoreDriverRequest;
use App\Http\Resources\Fleet\DriverResource;
use App\Identity\Services\AuthorizationService;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class DriverController extends FleetController
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
            'availability_status' => ['nullable', 'string', 'max:30'],
            'licence_expiry_before' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeOrganization($request, 'driver.view', $data['organization_id']);
        $query = Driver::query()->with(['licences.classes'])
            ->where('organization_id', $data['organization_id'])
            ->when($data['query'] ?? null, function ($builder, $value): void {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
                $prefix = "{$escaped}%";
                $builder->where(fn ($nested) => $nested->where('employee_number', 'like', $prefix)->orWhere('full_name', 'like', $prefix));
            })
            ->when($data['status'] ?? null, fn ($builder, $value) => $builder->where('status', $value))
            ->when($data['availability_status'] ?? null, fn ($builder, $value) => $builder->where('availability_status', $value))
            ->when($data['licence_expiry_before'] ?? null, fn ($builder, $value) => $builder->whereHas('licences', fn ($licences) => $licences->whereDate('expires_on', '<=', $value)))
            ->orderBy('full_name');

        return DriverResource::collection($query->paginate($data['per_page'] ?? 25));
    }

    public function store(StoreDriverRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->authorizeOrganization($request, 'driver.create', $data['organization_id']);

        return (new DriverResource(
            $this->registry->createDriver($data, $this->actor($request), $this->session($request), $request)->load('licences.classes'),
        ))->response()->setStatusCode(201);
    }

    public function show(Request $request, Driver $driver): DriverResource
    {
        $this->authorizeOrganization($request, 'driver.view', $driver->organization_id, 'driver', $driver->id);

        return (new DriverResource($driver->load(['licences.classes', 'assignments.vehicle'])))->additional(['history' => [
            'statuses' => \DB::table('driver_status_history')->where('driver_id', $driver->id)->orderByDesc('effective_at')->get(),
            'qualifications' => \DB::table('driver_qualifications')->where('driver_id', $driver->id)->orderByDesc('created_at')->get(),
            'restrictions' => \DB::table('driver_restrictions')->where('driver_id', $driver->id)->orderByDesc('created_at')->get(),
            'documents' => $this->documentSummaries('driver', $driver->id, $driver->organization_id),
        ]]);
    }

    public function status(Request $request, Driver $driver): DriverResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'on_leave', 'suspended', 'inactive', 'terminated'])],
            'availability_status' => ['required', Rule::in(['available', 'assigned', 'unavailable', 'on_leave'])],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->authorizeOrganization($request, 'driver.status.manage', $driver->organization_id, 'driver', $driver->id);

        return new DriverResource($this->registry->transitionDriver($driver, $data, $this->actor($request), $this->session($request), $request));
    }

    public function licence(Request $request, Driver $driver): DriverResource
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'min:3', 'max:190'],
            'issuing_authority' => ['required', 'string', 'max:190'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['required', 'date', 'after:today'],
            'status' => ['required', Rule::in(['pending_verification', 'verified'])],
            'document_id' => ['nullable', 'string', 'size:26', 'exists:documents,id'],
            'class_ids' => ['required', 'array', 'min:1'],
            'class_ids.*' => ['string', 'size:26', 'distinct', 'exists:driver_licence_classes,id'],
        ]);
        $this->authorizeOrganization($request, 'driver.licence.manage', $driver->organization_id, 'driver', $driver->id);

        return new DriverResource($this->registry->renewDriverLicence($driver, $data, $this->actor($request), $this->session($request), $request)->load('licences.classes'));
    }
}
