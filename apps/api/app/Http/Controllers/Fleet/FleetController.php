<?php

namespace App\Http\Controllers\Fleet;

use App\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FleetController extends Controller
{
    public function __construct(
        protected readonly AuthorizationService $authorization,
        protected readonly AuditService $audit,
        protected readonly OutboxService $outbox,
    ) {}

    public function referenceData(Request $request): JsonResponse
    {
        $organizationId = $request->validate(['organization_id' => ['required', 'string', 'size:26']])['organization_id'];
        $this->authorizeOrganization($request, 'fleet.reference.view', $organizationId);

        return response()->json(['data' => [
            'categories' => DB::table('vehicle_categories')->where('status', 'active')->orderBy('code')->get(),
            'classes' => DB::table('vehicle_classes')->where('status', 'active')->orderBy('code')->get(),
            'manufacturers' => DB::table('vehicle_manufacturers')->where('status', 'active')->orderBy('name')->get(),
            'models' => DB::table('vehicle_models')->where('status', 'active')->orderBy('name')->get(),
            'trims' => DB::table('vehicle_trims')->where('status', 'active')->orderBy('name')->get(),
            'licence_classes' => DB::table('driver_licence_classes')->where('status', 'active')->orderBy('code')->get(),
            'fleet_units' => DB::table('fleet_units')->where('organization_id', $organizationId)->where('status', 'active')->orderBy('code')->get(),
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string', 'size:26']]);
        $organizationId = $data['organization_id'];
        $this->authorizeOrganization($request, 'fleet.dashboard.view', $organizationId);
        $vehicleBase = DB::table('vehicles')->where('custodian_organization_id', $organizationId);
        $driverBase = DB::table('drivers')->where('organization_id', $organizationId);
        $expiring = DB::table('fleet_compliance_records')->where('organization_id', $organizationId)
            ->where('status', 'current')->whereBetween('expires_on', [today(), today()->addDays(30)])->count();
        $expired = DB::table('fleet_compliance_records')->where('organization_id', $organizationId)
            ->where('status', 'current')->where('expires_on', '<', today())->count();

        return response()->json(['data' => [
            'vehicles' => [
                'total' => (clone $vehicleBase)->count(),
                'active' => (clone $vehicleBase)->where('status', 'active')->count(),
                'unavailable' => (clone $vehicleBase)->whereIn('status', ['suspended', 'under_maintenance', 'out_of_service'])->count(),
                'retired' => (clone $vehicleBase)->where('status', 'retired')->count(),
                'unassigned' => (clone $vehicleBase)->whereNotExists(fn ($query) => $query->selectRaw('1')->from('vehicle_driver_assignments')
                    ->whereColumn('vehicle_driver_assignments.vehicle_id', 'vehicles.id')->where('vehicle_driver_assignments.status', 'active'))->count(),
            ],
            'drivers' => [
                'total' => (clone $driverBase)->count(),
                'available' => (clone $driverBase)->where('status', 'active')->where('availability_status', 'available')->count(),
                'assigned' => (clone $driverBase)->where('availability_status', 'assigned')->count(),
                'licences_expiring_30_days' => DB::table('driver_licences')->join('drivers', 'drivers.id', '=', 'driver_licences.driver_id')
                    ->where('drivers.organization_id', $organizationId)->where('driver_licences.status', 'verified')
                    ->whereBetween('driver_licences.expires_on', [today(), today()->addDays(30)])->count(),
            ],
            'compliance' => ['expiring_30_days' => $expiring, 'expired' => $expired],
            'assignments' => [
                'active' => DB::table('vehicle_driver_assignments')->where('organization_id', $organizationId)->where('status', 'active')->count(),
                'awaiting_acknowledgement' => DB::table('vehicle_driver_assignments')->where('organization_id', $organizationId)->where('status', 'active')
                    ->where('acknowledgement_required', true)->whereNull('acknowledged_at')->count(),
            ],
            'generated_at' => now()->toISOString(),
        ]]);
    }

    public function storeReference(Request $request, string $resource): JsonResponse
    {
        $config = [
            'categories' => ['vehicle_categories', [
                'code' => ['required', 'string', 'max:50', 'unique:vehicle_categories,code'],
                'name' => ['required', 'array:en,om,am'],
                'name.en' => ['required', 'string', 'max:120'],
                'name.om' => ['required', 'string', 'max:120'],
                'name.am' => ['required', 'string', 'max:120'],
            ]],
            'classes' => ['vehicle_classes', [
                'vehicle_category_id' => ['required', 'string', 'size:26', 'exists:vehicle_categories,id'],
                'code' => ['required', 'string', 'max:50', 'unique:vehicle_classes,code'],
                'name' => ['required', 'array:en,om,am'],
                'name.en' => ['required', 'string', 'max:120'],
                'name.om' => ['required', 'string', 'max:120'],
                'name.am' => ['required', 'string', 'max:120'],
                'default_capacity_kg' => ['nullable', 'numeric', 'min:0'],
                'default_seating_capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            ]],
            'manufacturers' => ['vehicle_manufacturers', [
                'code' => ['required', 'string', 'max:50', 'unique:vehicle_manufacturers,code'],
                'name' => ['required', 'string', 'max:120', 'unique:vehicle_manufacturers,name'],
            ]],
            'models' => ['vehicle_models', [
                'manufacturer_id' => ['required', 'string', 'size:26', 'exists:vehicle_manufacturers,id'],
                'code' => ['required', 'string', 'max:80'],
                'name' => ['required', 'string', 'max:120'],
            ]],
            'trims' => ['vehicle_trims', [
                'vehicle_model_id' => ['required', 'string', 'size:26', 'exists:vehicle_models,id'],
                'code' => ['required', 'string', 'max:80'],
                'name' => ['required', 'string', 'max:120'],
            ]],
            'licence-classes' => ['driver_licence_classes', [
                'code' => ['required', 'string', 'max:50', 'unique:driver_licence_classes,code'],
                'name' => ['required', 'array:en,om,am'],
                'name.en' => ['required', 'string', 'max:120'],
                'name.om' => ['required', 'string', 'max:120'],
                'name.am' => ['required', 'string', 'max:120'],
            ]],
            'fleet-units' => ['fleet_units', [
                'code' => ['required', 'string', 'max:80'],
                'name' => ['required', 'array:en,om,am'],
                'name.en' => ['required', 'string', 'max:120'],
                'name.om' => ['required', 'string', 'max:120'],
                'name.am' => ['required', 'string', 'max:120'],
                'physical_base' => ['nullable', 'string', 'max:255'],
                'cost_reference' => ['nullable', 'string', 'max:120'],
            ]],
        ];
        abort_unless(isset($config[$resource]), 404);
        [$table, $rules] = $config[$resource];
        $organizationId = $request->validate(['organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id']])['organization_id'];
        $this->authorizeOrganization($request, 'fleet.reference.manage', $organizationId);
        $data = $request->validate($rules);
        $id = (string) Str::ulid();
        $row = [...$data, 'id' => $id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()];
        if (isset($row['name']) && is_array($row['name'])) {
            $row['name'] = json_encode($row['name'], JSON_THROW_ON_ERROR);
        }
        if ($resource === 'fleet-units') {
            $row['organization_id'] = $organizationId;
            $row['record_version'] = 1;
        }
        if ($resource === 'licence-classes') {
            $row['effective_from'] = now();
            $row['effective_to'] = null;
        }
        DB::transaction(function () use ($table, $row, $id, $organizationId, $resource, $request): void {
            DB::table($table)->insert($row);
            $this->recordReferenceChange($request, $organizationId, $resource, $id, 'created');
        });

        return response()->json(['data' => DB::table($table)->where('id', $id)->first()], 201);
    }

    public function linkVehicleLicenceClass(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'vehicle_class_id' => ['required', 'string', 'size:26', 'exists:vehicle_classes,id'],
            'driver_licence_class_id' => ['required', 'string', 'size:26', 'exists:driver_licence_classes,id'],
        ]);
        $this->authorizeOrganization($request, 'fleet.reference.manage', $data['organization_id']);
        DB::transaction(function () use ($data, $request): void {
            $created = DB::table('vehicle_class_licence_classes')->insertOrIgnore([
                'vehicle_class_id' => $data['vehicle_class_id'],
                'driver_licence_class_id' => $data['driver_licence_class_id'],
            ]);
            if ($created === 1) {
                $this->recordReferenceChange(
                    $request,
                    $data['organization_id'],
                    'vehicle_licence_compatibility',
                    $data['vehicle_class_id'].':'.$data['driver_licence_class_id'],
                    'linked',
                );
            }
        });

        return response()->json(['data' => [
            'vehicle_class_id' => $data['vehicle_class_id'],
            'driver_licence_class_id' => $data['driver_licence_class_id'],
        ]], 201);
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    protected function session(Request $request): ?UserSession
    {
        $session = $request->attributes->get('identity_session');

        return $session instanceof UserSession ? $session : null;
    }

    protected function authorizeOrganization(
        Request $request,
        string $permission,
        string $organizationId,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        abort_unless($this->authorization->allows(
            $this->actor($request), $permission, $organizationId, $resourceType, $resourceId, $this->session($request),
        ), 403);
    }

    /** @return Collection<int, \stdClass> */
    protected function documentSummaries(string $entityType, string $entityId, string $organizationId): Collection
    {
        return DB::table('documents as documents')
            ->join('document_types as types', 'types.id', '=', 'documents.document_type_id')
            ->leftJoin('document_versions as versions', 'versions.id', '=', 'documents.current_version_id')
            ->where('documents.owner_type', $entityType)
            ->where('documents.owner_id', $entityId)
            ->where('documents.organization_id', $organizationId)
            ->select([
                'documents.id',
                'types.code as document_type',
                'documents.category',
                'documents.classification',
                'documents.status',
                'documents.expires_at',
                'documents.record_version',
                'versions.original_filename',
                'versions.media_type',
                'versions.size_bytes',
                'versions.scan_status',
                'versions.trust_status',
                'documents.created_at',
            ])
            ->orderByDesc('documents.created_at')
            ->get();
    }

    private function recordReferenceChange(Request $request, string $organizationId, string $resource, string $id, string $action): void
    {
        $event = 'fleet.reference.'.$action;
        $this->audit->record(
            $event.'.succeeded', 'fleet', $action, 'succeeded', $resource, $id,
            $organizationId, $this->actor($request), $this->session($request),
            after: ['resource' => $resource, 'id' => $id], request: $request,
        );
        $this->outbox->enqueue(
            $event, $resource, $id, ['id' => $id, 'organization_id' => $organizationId],
            $event.':'.$resource.':'.$id, $organizationId,
            $request->attributes->get('correlation_id'), $request->attributes->get('causation_id'),
        );
    }
}
