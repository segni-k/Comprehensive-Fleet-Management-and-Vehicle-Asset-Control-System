<?php

namespace App\Http\Controllers\Geography;

use App\Audit\Services\AuditService;
use App\Documents\Models\Document;
use App\Geography\Models\DistanceReferenceVersion;
use App\Geography\Models\LocationPolicyVersion;
use App\Geography\Models\OperationalZone;
use App\Geography\Models\Place;
use App\Geography\Models\PlaceCategory;
use App\Geography\Models\RouteMaster;
use App\Geography\Models\RouteVersion;
use App\Geography\Services\GeographyImportService;
use App\Geography\Services\GeographyRegistryService;
use App\Http\Controllers\Controller;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class GeographyController extends Controller
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly GeographyRegistryService $registry,
        private readonly GeographyImportService $imports,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request, 'geography.dashboard.view');
        $places = DB::table('places')->where('owning_organization_id', $organizationId);
        $routes = DB::table('route_masters')->where('organization_id', $organizationId);

        return response()->json(['data' => [
            'places' => [
                'total' => (clone $places)->count(),
                'active' => (clone $places)->where('status', 'active')->count(),
                'without_coordinates' => (clone $places)->whereNull('latitude')->count(),
                'inactive' => (clone $places)->where('status', 'inactive')->count(),
            ],
            'routes' => [
                'total' => (clone $routes)->count(),
                'active' => (clone $routes)->where('status', 'active')->count(),
                'draft_versions' => DB::table('route_versions')->join('route_masters', 'route_masters.id', '=', 'route_versions.route_master_id')
                    ->where('route_masters.organization_id', $organizationId)->where('route_versions.status', 'draft')->count(),
            ],
            'distance_references' => [
                'approved' => DB::table('distance_reference_versions')->where('organization_id', $organizationId)->where('status', 'approved')->count(),
                'draft' => DB::table('distance_reference_versions')->where('organization_id', $organizationId)->where('status', 'draft')->count(),
                'legs' => DB::table('distance_reference_legs')->join('distance_reference_versions', 'distance_reference_versions.id', '=', 'distance_reference_legs.version_id')
                    ->where('distance_reference_versions.organization_id', $organizationId)->count(),
            ],
            'operational_zones' => DB::table('operational_zones')->where('organization_id', $organizationId)->where('status', 'active')->count(),
            'generated_at' => now()->toISOString(),
        ]]);
    }

    public function myOperationalReference(Request $request): JsonResponse
    {
        $driver = DB::table('drivers')->where('user_id', $this->actor($request)->id)
            ->where('status', 'active')->first();
        abort_unless($driver !== null, 403);
        $this->authorize($request, 'geography.own.view', $driver->organization_id);
        $now = now();
        $places = Place::query()->where('owning_organization_id', $driver->organization_id)
            ->where('status', 'active')->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->select(['id', 'code', 'name', 'place_category_id', 'latitude', 'longitude'])
            ->orderBy('code')->limit(500)->get();
        $routes = RouteMaster::query()->where('organization_id', $driver->organization_id)->where('status', 'active')
            ->with(['versions' => fn ($query) => $query->where('status', 'approved')
                ->where('effective_from', '<=', $now)
                ->where(fn ($period) => $period->whereNull('effective_to')->orWhere('effective_to', '>', $now))
                ->with('segments')->orderByDesc('preferred')->orderByDesc('version')])
            ->orderBy('code')->limit(250)->get();
        $distanceLegs = DB::table('distance_reference_legs as legs')
            ->join('distance_reference_versions as versions', 'versions.id', '=', 'legs.version_id')
            ->where('versions.organization_id', $driver->organization_id)->where('versions.status', 'approved')
            ->where('versions.effective_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('versions.effective_to')->orWhere('versions.effective_to', '>', $now))
            ->select(['legs.origin_place_id', 'legs.destination_place_id', 'legs.route_label', 'legs.distance_km', 'legs.estimated_duration_minutes', 'legs.directional'])
            ->limit(2000)->get();

        return response()->json(['data' => [
            'places' => $places,
            'routes' => $routes,
            'distance_legs' => $distanceLegs,
            'synchronized_at' => $now->toISOString(),
        ]]);
    }

    public function categories(Request $request): JsonResponse
    {
        $this->authorize($request, 'geography.reference.view', $request->query('organization_id'));

        return response()->json(['data' => PlaceCategory::query()->orderBy('classification')->orderBy('code')->get()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request, 'geography.reference.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash:ascii', 'unique:place_categories,code'],
            ...$this->localizedNameRules(),
            'classification' => ['required', Rule::in(['administrative', 'facility', 'operational', 'custom'])],
            'allows_children' => ['required', 'boolean'],
            'requires_coordinates' => ['required', 'boolean'],
        ]);
        $category = DB::transaction(function () use ($data, $organizationId, $request): PlaceCategory {
            $category = PlaceCategory::query()->create([...$data, 'system_defined' => false, 'status' => 'active']);
            $this->recordReference($request, $organizationId, 'place_category', $category->id, 'created');

            return $category;
        });

        return response()->json(['data' => $category], 201);
    }

    public function places(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'query' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'inactive'])],
            'category_id' => ['nullable', 'string', 'size:26'],
            'classification' => ['nullable', Rule::in(['administrative', 'facility', 'operational', 'custom'])],
            'parent_place_id' => ['nullable', 'string', 'size:26'],
            'has_coordinates' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorize($request, 'place.view', $data['organization_id']);
        $query = Place::query()->with('category')->where('owning_organization_id', $data['organization_id'])
            ->when($data['query'] ?? null, function ($builder, string $value): void {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
                $builder->where(fn ($nested) => $nested->where('code', 'like', "{$escaped}%")
                    ->orWhere('name', 'like', "%{$escaped}%")
                    ->orWhere('external_reference', 'like', "{$escaped}%"));
            })
            ->when($data['status'] ?? null, fn ($builder, $value) => $builder->where('status', $value))
            ->when($data['category_id'] ?? null, fn ($builder, $value) => $builder->where('place_category_id', $value))
            ->when($data['classification'] ?? null, fn ($builder, $value) => $builder->whereHas('category', fn ($category) => $category->where('classification', $value)))
            ->when(array_key_exists('has_coordinates', $data), fn ($builder) => $data['has_coordinates'] ? $builder->whereNotNull('latitude') : $builder->whereNull('latitude'))
            ->when($data['parent_place_id'] ?? null, fn ($builder, $value) => $builder->whereIn('id', DB::table('place_hierarchy_edges')
                ->where('parent_place_id', $value)->whereNull('effective_to')->select('child_place_id')))
            ->orderBy('code');

        return response()->json($query->paginate($data['per_page'] ?? 25));
    }

    public function storePlace(Request $request): JsonResponse
    {
        $data = $request->validate($this->placeRules());
        $this->authorize($request, 'place.manage', $data['owning_organization_id']);
        if (isset($data['administrative_organization_id'])) {
            $this->authorize($request, 'place.manage', $data['administrative_organization_id']);
        }

        return response()->json(['data' => $this->registry->createPlace($data, $this->actor($request), $this->session($request), $request)], 201);
    }

    public function showPlace(Request $request, Place $place): JsonResponse
    {
        $this->authorize($request, 'place.view', $place->owning_organization_id, 'place', $place->id);
        $place->load('category', 'policies');

        return response()->json(['data' => $place, 'history' => [
            'addresses' => DB::table('place_addresses')->where('place_id', $place->id)->orderByDesc('effective_from')->get(),
            'hierarchy' => DB::table('place_hierarchy_edges')->where('child_place_id', $place->id)->orWhere('parent_place_id', $place->id)->orderByDesc('effective_from')->get(),
            'organization_mappings' => DB::table('place_organization_mappings')->where('place_id', $place->id)->orderByDesc('effective_from')->get(),
            'events' => DB::table('place_history')->where('place_id', $place->id)->orderByDesc('effective_at')->get(),
        ]]);
    }

    public function updatePlace(Request $request, Place $place): JsonResponse
    {
        $this->authorize($request, 'place.manage', $place->owning_organization_id, 'place', $place->id);
        $data = $request->validate([
            'name' => ['sometimes', 'array:en,om,am'],
            'name.en' => ['required_with:name', 'string', 'max:200'],
            'name.om' => ['required_with:name', 'string', 'max:200'],
            'name.am' => ['required_with:name', 'string', 'max:200'],
            'place_category_id' => ['sometimes', 'string', 'size:26', 'exists:place_categories,id'],
            'administrative_organization_id' => ['nullable', 'string', 'size:26', 'exists:organizations,id'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'elevation_m' => ['nullable', 'integer', 'between:0,5000'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'change_reason' => ['required', 'string', 'min:3', 'max:2000'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->registry->updatePlace($place, $data, $this->actor($request), $this->session($request), $request)]);
    }

    public function transitionPlace(Request $request, Place $place): JsonResponse
    {
        $this->authorize($request, 'place.approve', $place->owning_organization_id, 'place', $place->id);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->registry->transitionPlace(
            $place, $data['status'], $data['record_version'], $data['reason'],
            $this->actor($request), $this->session($request), $request,
        )]);
    }

    public function attachParent(Request $request, Place $place): JsonResponse
    {
        $this->authorize($request, 'place.hierarchy.manage', $place->owning_organization_id, 'place', $place->id);
        $data = $request->validate([
            'parent_place_id' => ['required', 'string', 'size:26', 'exists:places,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        DB::transaction(fn () => $this->registry->attachParent($place, $data, $this->actor($request)));

        return response()->json(['data' => ['place_id' => $place->id, ...$data]], 201);
    }

    public function tree(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request, 'place.view');
        $places = Place::query()->with('category')->where('owning_organization_id', $organizationId)->orderBy('code')->get();
        $edges = DB::table('place_hierarchy_edges')->whereIn('child_place_id', $places->pluck('id'))
            ->where('effective_from', '<=', now())->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))->get();

        return response()->json(['data' => ['places' => $places, 'edges' => $edges]]);
    }

    public function createPolicy(Request $request, Place $place): JsonResponse
    {
        $this->authorize($request, 'place.policy.manage', $place->owning_organization_id, 'place', $place->id);
        $data = $request->validate($this->policyRules());

        return response()->json(['data' => $this->registry->createLocationPolicy(
            $place, $data, $this->actor($request), $this->session($request), $request,
        )], 201);
    }

    public function approvePolicy(Request $request, LocationPolicyVersion $policy): JsonResponse
    {
        $place = Place::query()->findOrFail($policy->place_id);
        $this->authorize($request, 'place.policy.approve', $place->owning_organization_id, 'place', $place->id);
        $data = $request->validate(['record_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->registry->approveLocationPolicy(
            $policy, $data['record_version'], $this->actor($request), $this->session($request), $request,
        )]);
    }

    public function routes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'query' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'inactive', 'retired'])],
            'origin_place_id' => ['nullable', 'string', 'size:26'],
            'destination_place_id' => ['nullable', 'string', 'size:26'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorize($request, 'route.view', $data['organization_id']);
        $query = RouteMaster::query()->with(['versions' => fn ($builder) => $builder->orderByDesc('version')])
            ->where('organization_id', $data['organization_id'])
            ->when($data['query'] ?? null, fn ($builder, $value) => $builder->where(fn ($nested) => $nested->where('code', 'like', "{$value}%")->orWhere('name', 'like', "%{$value}%")))
            ->when($data['status'] ?? null, fn ($builder, $value) => $builder->where('status', $value))
            ->when($data['origin_place_id'] ?? null, fn ($builder, $value) => $builder->where('origin_place_id', $value))
            ->when($data['destination_place_id'] ?? null, fn ($builder, $value) => $builder->where('destination_place_id', $value))
            ->orderBy('code');

        return response()->json($query->paginate($data['per_page'] ?? 25));
    }

    public function storeRoute(Request $request): JsonResponse
    {
        $data = $request->validate($this->routeRules());
        $this->authorize($request, 'route.manage', $data['organization_id']);

        return response()->json(['data' => $this->registry->createRoute($data, $this->actor($request), $this->session($request), $request)], 201);
    }

    public function showRoute(Request $request, RouteMaster $route): JsonResponse
    {
        $this->authorize($request, 'route.view', $route->organization_id, 'route', $route->id);

        return response()->json(['data' => $route->load('versions.segments'), 'history' => [
            'restrictions' => DB::table('route_restrictions')->whereIn('route_version_id', $route->versions->pluck('id'))->orderByDesc('effective_from')->get(),
        ]]);
    }

    public function createRouteVersion(Request $request, RouteMaster $route): JsonResponse
    {
        $this->authorize($request, 'route.manage', $route->organization_id, 'route', $route->id);
        $data = $request->validate($this->routeVersionRules());

        return response()->json(['data' => $this->registry->createRouteVersion(
            $route, $data, $this->actor($request), $this->session($request), $request,
        )], 201);
    }

    public function approveRouteVersion(Request $request, RouteVersion $version): JsonResponse
    {
        $route = RouteMaster::query()->findOrFail($version->route_master_id);
        $this->authorize($request, 'route.approve', $route->organization_id, 'route', $route->id);
        $data = $request->validate(['record_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->registry->approveRouteVersion(
            $version, $data['record_version'], $this->actor($request), $this->session($request), $request,
        )]);
    }

    public function distanceReferences(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request, 'distance.view');

        return response()->json(DistanceReferenceVersion::query()->withCount('legs')->where('organization_id', $organizationId)
            ->orderByDesc('effective_from')->paginate($request->integer('per_page', 25)));
    }

    public function storeDistanceReference(Request $request): JsonResponse
    {
        $data = $request->validate($this->distanceReferenceRules());
        $this->authorize($request, 'distance.manage', $data['organization_id']);

        return response()->json(['data' => $this->registry->createDistanceReference(
            $data, $this->actor($request), $this->session($request), $request,
        )], 201);
    }

    public function showDistanceReference(Request $request, DistanceReferenceVersion $reference): JsonResponse
    {
        $this->authorize($request, 'distance.view', $reference->organization_id, 'distance_reference', $reference->id);

        return response()->json(['data' => $reference->load('legs')]);
    }

    public function approveDistanceReference(Request $request, DistanceReferenceVersion $reference): JsonResponse
    {
        $this->authorize($request, 'distance.approve', $reference->organization_id, 'distance_reference', $reference->id);
        $data = $request->validate(['record_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->registry->approveDistanceReference(
            $reference, $data['record_version'], $this->actor($request), $this->session($request), $request,
        )]);
    }

    public function matrix(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'origin_place_id' => ['nullable', 'string', 'size:26'],
            'destination_place_id' => ['nullable', 'string', 'size:26'],
        ]);
        $this->authorize($request, 'distance.view', $data['organization_id']);
        $query = DB::table('distance_reference_legs as legs')
            ->join('distance_reference_versions as versions', 'versions.id', '=', 'legs.version_id')
            ->join('places as origin', 'origin.id', '=', 'legs.origin_place_id')
            ->join('places as destination', 'destination.id', '=', 'legs.destination_place_id')
            ->where('versions.organization_id', $data['organization_id'])
            ->where('versions.status', 'approved')
            ->where('versions.effective_from', '<=', now())
            ->where(fn ($builder) => $builder->whereNull('versions.effective_to')->orWhere('versions.effective_to', '>', now()))
            ->when($data['origin_place_id'] ?? null, fn ($builder, $value) => $builder->where('legs.origin_place_id', $value))
            ->when($data['destination_place_id'] ?? null, fn ($builder, $value) => $builder->where('legs.destination_place_id', $value))
            ->select(['legs.*', 'versions.code as version_code', 'versions.source_type', 'origin.name as origin_name', 'destination.name as destination_name'])
            ->orderBy('origin.code')->orderBy('destination.code');

        return response()->json(['data' => $query->get()]);
    }

    public function operationalZones(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request, 'geography.zone.view');

        return response()->json(['data' => OperationalZone::query()->where('organization_id', $organizationId)->orderBy('code')->get()]);
    }

    public function storeOperationalZone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'code' => ['required', 'string', 'max:80'],
            ...$this->localizedNameRules(),
            'description' => ['nullable', 'string', 'max:2000'],
            'zone_type' => ['required', Rule::in(['service', 'restricted', 'administrative', 'custom'])],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'places' => ['sometimes', 'array', 'max:500'],
            'places.*.place_id' => ['required', 'string', 'size:26', 'exists:places,id'],
            'places.*.membership_type' => ['sometimes', Rule::in(['included', 'excluded'])],
            'places.*.is_primary' => ['sometimes', 'boolean'],
            'places.*.effective_from' => ['sometimes', 'date'],
            'places.*.effective_to' => ['nullable', 'date'],
        ]);
        $this->authorize($request, 'geography.zone.manage', $data['organization_id']);

        return response()->json(['data' => $this->registry->createOperationalZone(
            $data, $this->actor($request), $this->session($request), $request,
        )], 201);
    }

    public function stageImport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'import_type' => ['required', Rule::in(['places', 'distance_matrix', 'routes'])],
            'document_id' => ['required', 'string', 'size:26', 'exists:documents,id'],
        ]);
        $this->authorize($request, 'geography.import.manage', $data['organization_id']);

        return response()->json(['data' => $this->imports->stage(
            $data['organization_id'],
            $data['import_type'],
            Document::query()->whereKey($data['document_id'])->firstOrFail(),
            $this->actor($request),
            $this->session($request),
            $request,
        )], 202);
    }

    public function importBatches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'status' => ['nullable', Rule::in(['validated', 'validation_failed', 'approved_applied_draft', 'rolled_back'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorize($request, 'geography.import.manage', $data['organization_id']);

        return response()->json(
            DB::table('route_distance_imports')
                ->where('organization_id', $data['organization_id'])
                ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->orderByDesc('created_at')
                ->paginate($data['per_page'] ?? 25),
        );
    }

    public function approveImport(Request $request, string $import): JsonResponse
    {
        $organizationId = (string) DB::table('route_distance_imports')->where('id', $import)->value('organization_id');
        $this->authorize($request, 'geography.import.approve', $organizationId, 'route_distance_import', $import);

        return response()->json(['data' => $this->imports->approve(
            $import, $this->actor($request), $this->session($request), $request,
        )]);
    }

    public function rollbackImport(Request $request, string $import): JsonResponse
    {
        $organizationId = (string) DB::table('route_distance_imports')->where('id', $import)->value('organization_id');
        $this->authorize($request, 'geography.import.approve', $organizationId, 'route_distance_import', $import);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->imports->rollback(
            $import, $data['reason'], $this->actor($request), $this->session($request), $request,
        )]);
    }

    /** @return array<string, mixed> */
    private function placeRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:80', 'alpha_dash:ascii', 'unique:places,code'],
            ...$this->localizedNameRules(),
            'place_category_id' => ['required', 'string', 'size:26', 'exists:place_categories,id'],
            'owning_organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'administrative_organization_id' => ['nullable', 'string', 'size:26', 'exists:organizations,id'],
            'parent_place_id' => ['nullable', 'string', 'size:26', 'exists:places,id'],
            'external_reference' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'elevation_m' => ['nullable', 'integer', 'between:0,5000'],
            'timezone' => ['sometimes', 'timezone'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'status' => ['required', Rule::in(['draft', 'active'])],
            'address' => ['sometimes', 'array'],
            'address.address_type' => ['required_with:address', Rule::in(['physical', 'postal', 'operational'])],
            'address.country_code' => ['sometimes', 'string', 'size:2'],
            'address.region' => ['nullable', 'string', 'max:120'],
            'address.zone' => ['nullable', 'string', 'max:120'],
            'address.woreda' => ['nullable', 'string', 'max:120'],
            'address.city_town' => ['nullable', 'string', 'max:120'],
            'address.kebele' => ['nullable', 'string', 'max:120'],
            'address.street' => ['nullable', 'string', 'max:190'],
            'address.postal_code' => ['nullable', 'string', 'max:30'],
            'address.directions' => ['nullable', 'string', 'max:2000'],
            'organization_mappings' => ['sometimes', 'array', 'max:100'],
            'organization_mappings.*.organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'organization_mappings.*.mapping_role' => ['required', Rule::in(['owner', 'administrator', 'operator', 'service_area'])],
            'organization_mappings.*.is_primary' => ['required', 'boolean'],
            'location_policy' => ['sometimes', 'array'],
            ...collect($this->policyRules())->mapWithKeys(fn ($rule, $key) => ["location_policy.{$key}" => $rule])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function policyRules(): array
    {
        return [
            'allowed_radius_m' => ['required', 'integer', 'between:10,10000'],
            'maximum_accuracy_m' => ['required', 'integer', 'between:1,5000'],
            'maximum_reading_age_seconds' => ['required', 'integer', 'between:15,86400'],
            'verifier_required' => ['required', 'boolean'],
            'qr_required' => ['required', 'boolean'],
            'photo_required' => ['required', 'boolean'],
            'offline_allowed' => ['required', 'boolean'],
            'maximum_offline_delay_minutes' => ['required', 'integer', 'between:0,10080'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ];
    }

    /** @return array<string, mixed> */
    private function routeRules(): array
    {
        return [
            'organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'code' => ['required', 'string', 'max:80'],
            ...$this->localizedNameRules(),
            'origin_place_id' => ['required', 'string', 'size:26', 'exists:places,id'],
            'destination_place_id' => ['required', 'string', 'size:26', 'exists:places,id'],
            'directional' => ['required', 'boolean'],
            'version' => ['sometimes', 'array'],
            ...collect($this->routeVersionRules())->mapWithKeys(fn ($rule, $key) => ["version.{$key}" => $rule])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function routeVersionRules(): array
    {
        return [
            'alternative_label' => ['required', 'string', 'max:190'],
            'preferred' => ['required', 'boolean'],
            'estimated_distance_km' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'estimated_duration_minutes' => ['required', 'integer', 'min:1', 'max:100000'],
            'source_type' => ['required', Rule::in(['bureau_matrix', 'approved_map', 'survey', 'manual'])],
            'source_reference' => ['required', 'string', 'max:500'],
            'source_document_id' => ['nullable', 'string', 'size:26', 'exists:documents,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'segments' => ['required', 'array', 'min:1', 'max:500'],
            'segments.*.sequence' => ['required', 'integer', 'min:1', 'distinct'],
            'segments.*.origin_place_id' => ['required', 'string', 'size:26', 'exists:places,id'],
            'segments.*.destination_place_id' => ['required', 'string', 'size:26', 'different:segments.*.origin_place_id', 'exists:places,id'],
            'segments.*.road_classification_id' => ['nullable', 'string', 'size:26', 'exists:road_classifications,id'],
            'segments.*.road_condition_id' => ['nullable', 'string', 'size:26', 'exists:road_conditions,id'],
            'segments.*.distance_km' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'segments.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:100000'],
            'segments.*.mandatory_stop' => ['required', 'boolean'],
            'segments.*.notes' => ['nullable', 'string', 'max:2000'],
            'restrictions' => ['sometimes', 'array', 'max:100'],
            'restrictions.*.restriction_type' => ['required', Rule::in(['vehicle_class', 'weight', 'height', 'seasonal', 'security', 'permit', 'other'])],
            'restrictions.*.description' => ['required', 'string', 'max:2000'],
            'restrictions.*.maximum_weight_kg' => ['nullable', 'numeric', 'gt:0'],
            'restrictions.*.maximum_height_m' => ['nullable', 'numeric', 'gt:0'],
            'restrictions.*.effective_from' => ['required', 'date'],
            'restrictions.*.effective_to' => ['nullable', 'date'],
            'restrictions.*.status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    /** @return array<string, mixed> */
    private function distanceReferenceRules(): array
    {
        return [
            'organization_id' => ['required', 'string', 'size:26', 'exists:organizations,id'],
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:200'],
            'source_type' => ['required', Rule::in(['bureau_matrix', 'approved_map', 'survey', 'manual'])],
            'source_reference' => ['required', 'string', 'max:500'],
            'source_document_id' => ['nullable', 'string', 'size:26', 'exists:documents,id'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'status' => ['sometimes', Rule::in(['draft'])],
            'legs' => ['required', 'array', 'min:1', 'max:10000'],
            'legs.*.origin_place_id' => ['required', 'string', 'size:26', 'exists:places,id'],
            'legs.*.destination_place_id' => ['required', 'string', 'size:26', 'exists:places,id'],
            'legs.*.route_version_id' => ['nullable', 'string', 'size:26', 'exists:route_versions,id'],
            'legs.*.route_label' => ['nullable', 'string', 'max:200'],
            'legs.*.distance_km' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'legs.*.estimated_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'legs.*.directional' => ['required', 'boolean'],
            'legs.*.tolerance_percent' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /** @return array<string, mixed> */
    private function localizedNameRules(): array
    {
        return [
            'name' => ['required', 'array:en,om,am'],
            'name.en' => ['required', 'string', 'max:200'],
            'name.om' => ['required', 'string', 'max:200'],
            'name.am' => ['required', 'string', 'max:200'],
        ];
    }

    private function organizationId(Request $request, string $permission): string
    {
        $organizationId = $request->validate(['organization_id' => ['required', 'string', 'size:26']])['organization_id'];
        $this->authorize($request, $permission, $organizationId);

        return $organizationId;
    }

    private function authorize(
        Request $request,
        string $permission,
        mixed $organizationId,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        abort_unless(is_string($organizationId) && strlen($organizationId) === 26, 422, 'A valid organization_id is required.');
        abort_unless($this->authorization->allows(
            $this->actor($request), $permission, $organizationId, $resourceType, $resourceId, $this->session($request),
        ), 403);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function session(Request $request): ?UserSession
    {
        $session = $request->attributes->get('identity_session');

        return $session instanceof UserSession ? $session : null;
    }

    private function recordReference(Request $request, string $organizationId, string $subjectType, string $id, string $action): void
    {
        $event = 'geography.'.$subjectType.'.'.$action;
        $this->audit->record(
            $event.'.succeeded', 'geography', $action, 'succeeded', $subjectType, $id,
            $organizationId, $this->actor($request), $this->session($request),
            after: ['id' => $id], request: $request,
        );
        $this->outbox->enqueue(
            $event, $subjectType, $id, ['id' => $id, 'organization_id' => $organizationId],
            $event.':'.$id, $organizationId,
            $request->attributes->get('correlation_id'), $request->attributes->get('causation_id'),
        );
    }
}
