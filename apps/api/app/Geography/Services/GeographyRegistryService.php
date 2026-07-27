<?php

namespace App\Geography\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Geography\Models\DistanceReferenceVersion;
use App\Geography\Models\LocationPolicyVersion;
use App\Geography\Models\OperationalZone;
use App\Geography\Models\Place;
use App\Geography\Models\RouteMaster;
use App\Geography\Models\RouteVersion;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Outbox\Services\OutboxService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class GeographyRegistryService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function createPlace(array $data, User $actor, ?UserSession $session, Request $request): Place
    {
        return DB::transaction(function () use ($data, $actor, $session, $request): Place {
            $this->assertCoordinatePair($data['latitude'] ?? null, $data['longitude'] ?? null);
            $category = DB::table('place_categories')->where('id', $data['place_category_id'])->lockForUpdate()->first();
            if ($category === null || $category->status !== 'active') {
                throw new BusinessRuleException('PLACE_CATEGORY_INACTIVE', 'An active place category is required.');
            }
            if ((bool) $category->requires_coordinates && (! isset($data['latitude']) || ! isset($data['longitude']))) {
                throw new BusinessRuleException('PLACE_COORDINATES_REQUIRED', 'This place category requires coordinates.');
            }

            $place = Place::query()->create(collect($data)->except(['parent_place_id', 'address', 'organization_mappings', 'location_policy'])->all());
            $now = now();
            if (isset($data['parent_place_id'])) {
                $this->attachParent($place, [
                    'parent_place_id' => $data['parent_place_id'],
                    'effective_from' => $place->effective_from,
                    'reason' => 'Initial approved place hierarchy.',
                ], $actor);
            }
            if (isset($data['address'])) {
                $this->insertAddress($place, $data['address'], $actor);
            }
            foreach ($data['organization_mappings'] ?? [] as $mapping) {
                DB::table('place_organization_mappings')->insert([
                    'id' => (string) Str::ulid(),
                    'place_id' => $place->id,
                    ...$mapping,
                    'effective_from' => $mapping['effective_from'] ?? $now,
                    'recorded_by' => $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->history($place, 'created', null, $place->status, null, $place->toArray(), $actor, 'Initial place master record.');
            if (isset($data['location_policy'])) {
                $this->createLocationPolicy($place, $data['location_policy'], $actor, $session, $request, false);
            }
            $this->record('place.created', 'create', $place, $place->owning_organization_id, $actor, $session, $request, null, $place->toArray());

            return $place->load('category');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updatePlace(Place $place, array $data, User $actor, ?UserSession $session, Request $request): Place
    {
        return DB::transaction(function () use ($place, $data, $actor, $session, $request): Place {
            /** @var Place $locked */
            $locked = Place::query()->whereKey($place->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, (int) $data['record_version']);
            $latitude = array_key_exists('latitude', $data) ? $data['latitude'] : $locked->latitude;
            $longitude = array_key_exists('longitude', $data) ? $data['longitude'] : $locked->longitude;
            $this->assertCoordinatePair($latitude, $longitude);
            $before = $locked->toArray();
            $locked->fill(collect($data)->except('record_version')->all());
            $locked->record_version++;
            $locked->save();
            $this->history($locked, 'updated', $before['status'], $locked->status, $before, $locked->toArray(), $actor, $data['change_reason'] ?? null);
            $this->record('place.updated', 'update', $locked, $locked->owning_organization_id, $actor, $session, $request, $before, $locked->toArray(), $data['change_reason'] ?? null);

            return $locked->load('category');
        }, 3);
    }

    public function transitionPlace(
        Place $place,
        string $status,
        int $recordVersion,
        string $reason,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): Place {
        return DB::transaction(function () use ($place, $status, $recordVersion, $reason, $actor, $session, $request): Place {
            /** @var Place $locked */
            $locked = Place::query()->whereKey($place->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, $recordVersion);
            $allowed = [
                'draft' => ['active', 'inactive'],
                'active' => ['inactive'],
                'inactive' => ['active'],
            ];
            if (! in_array($status, $allowed[$locked->status] ?? [], true)) {
                throw new BusinessRuleException('PLACE_STATUS_TRANSITION_INVALID', 'The requested place status transition is not allowed.');
            }
            if ($status === 'active') {
                $categoryRequiresCoordinates = (bool) DB::table('place_categories')->where('id', $locked->place_category_id)->value('requires_coordinates');
                if ($categoryRequiresCoordinates && ($locked->latitude === null || $locked->longitude === null)) {
                    throw new BusinessRuleException('PLACE_ACTIVATION_COORDINATES_REQUIRED', 'Coordinates are required before this place can be activated.');
                }
            }
            $before = $locked->toArray();
            $locked->forceFill(['status' => $status, 'record_version' => $locked->record_version + 1])->save();
            $this->history($locked, 'status_changed', $before['status'], $status, $before, $locked->toArray(), $actor, $reason);
            $this->record('place.status.changed', 'transition', $locked, $locked->owning_organization_id, $actor, $session, $request, $before, $locked->toArray(), $reason);

            return $locked->load('category');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function attachParent(Place $place, array $data, User $actor): void
    {
        $parentId = (string) $data['parent_place_id'];
        if ($parentId === $place->id || $this->isDescendant($place->id, $parentId)) {
            throw new BusinessRuleException('PLACE_HIERARCHY_CYCLE', 'The place hierarchy change would create a cycle.');
        }
        $parent = Place::query()->whereKey($parentId)->lockForUpdate()->firstOrFail();
        $this->assertPlaceInOrganization($parentId, $place->owning_organization_id);
        if ($parent->status === 'inactive') {
            throw new BusinessRuleException('PLACE_PARENT_INACTIVE', 'An inactive place cannot become a hierarchy parent.');
        }
        $allowsChildren = (bool) DB::table('place_categories')
            ->where('id', $parent->place_category_id)
            ->value('allows_children');
        if (! $allowsChildren) {
            throw new BusinessRuleException('PLACE_PARENT_CATEGORY_DISALLOWS_CHILDREN', 'The selected parent category does not allow child places.');
        }
        $effectiveFrom = $data['effective_from'];
        $overlap = DB::table('place_hierarchy_edges')
            ->where('child_place_id', $place->id)
            ->where('effective_from', '<', $data['effective_to'] ?? '9999-12-31 23:59:59')
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $effectiveFrom))
            ->exists();
        if ($overlap) {
            throw new BusinessRuleException('PLACE_PARENT_PERIOD_OVERLAP', 'A place may have only one effective parent at a time.');
        }
        DB::table('place_hierarchy_edges')->insert([
            'id' => (string) Str::ulid(),
            'parent_place_id' => $parentId,
            'child_place_id' => $place->id,
            'effective_from' => $effectiveFrom,
            'effective_to' => $data['effective_to'] ?? null,
            'reason' => $data['reason'],
            'approved_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function createLocationPolicy(
        Place $place,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
        bool $record = true,
    ): LocationPolicyVersion {
        return DB::transaction(function () use ($place, $data, $actor, $session, $request, $record): LocationPolicyVersion {
            Place::query()->whereKey($place->id)->lockForUpdate()->firstOrFail();
            $version = ((int) LocationPolicyVersion::query()->where('place_id', $place->id)->max('version')) + 1;
            $policy = LocationPolicyVersion::query()->create([
                ...$data,
                'place_id' => $place->id,
                'version' => $version,
                'status' => 'draft',
            ]);
            if ($record) {
                $this->record('location_policy.created', 'create', $policy, $place->owning_organization_id, $actor, $session, $request, null, $policy->toArray());
            }

            return $policy;
        }, 3);
    }

    public function approveLocationPolicy(
        LocationPolicyVersion $policy,
        int $recordVersion,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): LocationPolicyVersion {
        return DB::transaction(function () use ($policy, $recordVersion, $actor, $session, $request): LocationPolicyVersion {
            /** @var LocationPolicyVersion $locked */
            $locked = LocationPolicyVersion::query()->whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, $recordVersion);
            if ($locked->status !== 'draft') {
                throw new BusinessRuleException('LOCATION_POLICY_NOT_DRAFT', 'Only a draft location policy can be approved.');
            }
            $overlap = LocationPolicyVersion::query()->where('place_id', $locked->place_id)->where('status', 'approved')
                ->where('effective_from', '<', $locked->effective_to ?? '9999-12-31 23:59:59')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $locked->effective_from))
                ->exists();
            if ($overlap) {
                throw new BusinessRuleException('LOCATION_POLICY_PERIOD_OVERLAP', 'Approved location policy periods may not overlap.');
            }
            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'record_version' => $locked->record_version + 1,
            ])->save();
            $organizationId = (string) Place::query()->whereKey($locked->place_id)->value('owning_organization_id');
            $this->record('location_policy.approved', 'approve', $locked, $organizationId, $actor, $session, $request, ['status' => 'draft'], $locked->toArray());

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createRoute(array $data, User $actor, ?UserSession $session, Request $request): RouteMaster
    {
        return DB::transaction(function () use ($data, $actor, $session, $request): RouteMaster {
            if ($data['origin_place_id'] === $data['destination_place_id']) {
                throw new BusinessRuleException('ROUTE_ENDPOINTS_IDENTICAL', 'Route origin and destination must be different.');
            }
            $this->assertPlaceInOrganization((string) $data['origin_place_id'], (string) $data['organization_id']);
            $this->assertPlaceInOrganization((string) $data['destination_place_id'], (string) $data['organization_id']);
            $route = RouteMaster::query()->create(collect($data)->except('version')->all());
            if (isset($data['version'])) {
                $this->createRouteVersion($route, $data['version'], $actor, $session, $request, false);
            }
            $this->record('route.created', 'create', $route, $route->organization_id, $actor, $session, $request, null, $route->toArray());

            return $route->load('versions.segments');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createRouteVersion(
        RouteMaster $route,
        array $data,
        User $actor,
        ?UserSession $session,
        Request $request,
        bool $record = true,
    ): RouteVersion {
        return DB::transaction(function () use ($route, $data, $actor, $session, $request, $record): RouteVersion {
            RouteMaster::query()->whereKey($route->id)->lockForUpdate()->firstOrFail();
            $segments = $data['segments'];
            foreach ($segments as $segment) {
                $this->assertPlaceInOrganization((string) $segment['origin_place_id'], $route->organization_id);
                $this->assertPlaceInOrganization((string) $segment['destination_place_id'], $route->organization_id);
            }
            $this->assertRouteSegments($route, $segments, (float) $data['estimated_distance_km'], (int) $data['estimated_duration_minutes']);
            $version = ((int) RouteVersion::query()->where('route_master_id', $route->id)->max('version')) + 1;
            $routeVersion = RouteVersion::query()->create([
                ...collect($data)->except(['segments', 'restrictions'])->all(),
                'route_master_id' => $route->id,
                'version' => $version,
                'status' => 'draft',
            ]);
            foreach ($segments as $segment) {
                $routeVersion->segments()->create($segment);
            }
            foreach ($data['restrictions'] ?? [] as $restriction) {
                DB::table('route_restrictions')->insert([
                    'id' => (string) Str::ulid(),
                    'route_version_id' => $routeVersion->id,
                    ...$restriction,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if ($record) {
                $this->record('route.version.created', 'create', $routeVersion, $route->organization_id, $actor, $session, $request, null, $routeVersion->toArray());
            }

            return $routeVersion->load('segments');
        }, 3);
    }

    public function approveRouteVersion(
        RouteVersion $version,
        int $recordVersion,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): RouteVersion {
        return DB::transaction(function () use ($version, $recordVersion, $actor, $session, $request): RouteVersion {
            /** @var RouteVersion $locked */
            $locked = RouteVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, $recordVersion);
            if ($locked->status !== 'draft' || ! $locked->segments()->exists()) {
                throw new BusinessRuleException('ROUTE_VERSION_NOT_APPROVABLE', 'Only a complete draft route version can be approved.');
            }
            if ($locked->preferred) {
                $overlap = RouteVersion::query()
                    ->where('route_master_id', $locked->route_master_id)
                    ->where('status', 'approved')
                    ->where('preferred', true)
                    ->where('effective_from', '<', $locked->effective_to ?? '9999-12-31 23:59:59')
                    ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $locked->effective_from))
                    ->exists();
                if ($overlap) {
                    throw new BusinessRuleException('ROUTE_PREFERRED_PERIOD_OVERLAP', 'Preferred approved route-version periods may not overlap.');
                }
            }
            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'record_version' => $locked->record_version + 1,
            ])->save();
            $route = RouteMaster::query()->whereKey($locked->route_master_id)->lockForUpdate()->firstOrFail();
            if ($route->status === 'draft') {
                $route->forceFill(['status' => 'active', 'record_version' => $route->record_version + 1])->save();
            }
            $this->record('route.version.approved', 'approve', $locked, $route->organization_id, $actor, $session, $request, ['status' => 'draft'], $locked->toArray());

            return $locked->load('segments');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createDistanceReference(array $data, User $actor, ?UserSession $session, Request $request): DistanceReferenceVersion
    {
        return DB::transaction(function () use ($data, $actor, $session, $request): DistanceReferenceVersion {
            $legs = $data['legs'];
            $seen = [];
            foreach ($legs as $leg) {
                $this->assertPlaceInOrganization((string) $leg['origin_place_id'], (string) $data['organization_id']);
                $this->assertPlaceInOrganization((string) $leg['destination_place_id'], (string) $data['organization_id']);
                if ($leg['origin_place_id'] === $leg['destination_place_id']) {
                    throw new BusinessRuleException('DISTANCE_ENDPOINTS_IDENTICAL', 'Distance origin and destination must be different.');
                }
                $key = implode('|', [$leg['origin_place_id'], $leg['destination_place_id'], $leg['route_label'] ?? '']);
                if (isset($seen[$key])) {
                    throw new BusinessRuleException('DISTANCE_LEG_DUPLICATE', 'Duplicate origin, destination, and route labels are not allowed in one version.');
                }
                $seen[$key] = true;
            }
            $reference = DistanceReferenceVersion::query()->create(collect($data)->except('legs')->all());
            foreach ($legs as $leg) {
                $reference->legs()->create($leg);
            }
            $this->record('distance_reference.created', 'create', $reference, $reference->organization_id, $actor, $session, $request, null, $reference->toArray());

            return $reference->load('legs');
        }, 3);
    }

    public function approveDistanceReference(
        DistanceReferenceVersion $reference,
        int $recordVersion,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): DistanceReferenceVersion {
        return DB::transaction(function () use ($reference, $recordVersion, $actor, $session, $request): DistanceReferenceVersion {
            /** @var DistanceReferenceVersion $locked */
            $locked = DistanceReferenceVersion::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($locked, $recordVersion);
            if ($locked->status !== 'draft' || ! $locked->legs()->exists()) {
                throw new BusinessRuleException('DISTANCE_REFERENCE_NOT_APPROVABLE', 'Only a populated draft distance reference can be approved.');
            }
            $overlap = DistanceReferenceVersion::query()->where('organization_id', $locked->organization_id)->where('status', 'approved')
                ->where('effective_from', '<', $locked->effective_to ?? '9999-12-31 23:59:59')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $locked->effective_from))
                ->exists();
            if ($overlap) {
                throw new BusinessRuleException('DISTANCE_REFERENCE_PERIOD_OVERLAP', 'Approved distance-reference periods may not overlap for an organization.');
            }
            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'record_version' => $locked->record_version + 1,
            ])->save();
            $this->record('distance_reference.approved', 'approve', $locked, $locked->organization_id, $actor, $session, $request, ['status' => 'draft'], $locked->toArray());

            return $locked->load('legs');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function createOperationalZone(array $data, User $actor, ?UserSession $session, Request $request): OperationalZone
    {
        return DB::transaction(function () use ($data, $actor, $session, $request): OperationalZone {
            $places = $data['places'] ?? [];
            foreach ($places as $place) {
                $this->assertPlaceInOrganization((string) $place['place_id'], (string) $data['organization_id']);
            }
            $zone = OperationalZone::query()->create(collect($data)->except('places')->all());
            foreach ($places as $place) {
                DB::table('operational_zone_places')->insert([
                    'operational_zone_id' => $zone->id,
                    'place_id' => $place['place_id'],
                    'membership_type' => $place['membership_type'] ?? 'included',
                    'is_primary' => $place['is_primary'] ?? false,
                    'effective_from' => $place['effective_from'] ?? $zone->effective_from,
                    'effective_to' => $place['effective_to'] ?? null,
                ]);
            }
            $this->record('operational_zone.created', 'create', $zone, $zone->organization_id, $actor, $session, $request, null, $zone->toArray());

            return $zone;
        }, 3);
    }

    private function assertCoordinatePair(mixed $latitude, mixed $longitude): void
    {
        if (($latitude === null) xor ($longitude === null)) {
            throw new BusinessRuleException('PLACE_COORDINATE_PAIR_REQUIRED', 'Latitude and longitude must be supplied together.');
        }
    }

    private function assertVersion(Model $model, int $recordVersion): void
    {
        if ((int) $model->getAttribute('record_version') !== $recordVersion) {
            throw new ConflictException('GEOGRAPHY_RECORD_VERSION_CONFLICT', 'The geography record was changed by another operation.');
        }
    }

    private function isDescendant(string $candidateParentId, string $candidateChildId): bool
    {
        $frontier = [$candidateParentId];
        $visited = [];
        while ($frontier !== []) {
            $current = array_pop($frontier);
            if ($current === $candidateChildId) {
                return true;
            }
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $frontier = array_merge($frontier, DB::table('place_hierarchy_edges')
                ->where('parent_place_id', $current)
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->pluck('child_place_id')->all());
        }

        return false;
    }

    /** @param array<string, mixed> $address */
    private function insertAddress(Place $place, array $address, User $actor): void
    {
        DB::table('place_addresses')->insert([
            'id' => (string) Str::ulid(),
            'place_id' => $place->id,
            ...$address,
            'effective_from' => $address['effective_from'] ?? $place->effective_from,
            'recorded_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertPlaceInOrganization(string $placeId, string $organizationId): void
    {
        $now = now();
        $visible = DB::table('places')
            ->where('id', $placeId)
            ->where(function ($query) use ($organizationId, $now): void {
                $query->where('owning_organization_id', $organizationId)
                    ->orWhereExists(function ($mapping) use ($organizationId, $now): void {
                        $mapping->selectRaw('1')
                            ->from('place_organization_mappings')
                            ->whereColumn('place_organization_mappings.place_id', 'places.id')
                            ->where('place_organization_mappings.organization_id', $organizationId)
                            ->where('place_organization_mappings.effective_from', '<=', $now)
                            ->where(fn ($period) => $period->whereNull('place_organization_mappings.effective_to')
                                ->orWhere('place_organization_mappings.effective_to', '>', $now));
                    });
            })
            ->exists();
        if (! $visible) {
            throw new BusinessRuleException('PLACE_ORGANIZATION_SCOPE_INVALID', 'The selected place is not owned by or effectively mapped to this organization.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    private function assertRouteSegments(RouteMaster $route, array $segments, float $distance, int $duration): void
    {
        if ($segments === []) {
            throw new BusinessRuleException('ROUTE_SEGMENTS_REQUIRED', 'A route version requires at least one segment.');
        }
        $expectedOrigin = $route->origin_place_id;
        $distanceTotal = 0.0;
        $durationTotal = 0;
        foreach ($segments as $index => $segment) {
            if ((int) $segment['sequence'] !== $index + 1 || $segment['origin_place_id'] !== $expectedOrigin) {
                throw new BusinessRuleException('ROUTE_SEGMENT_DISCONTINUITY', 'Route segments must be sequential and geographically continuous.');
            }
            $expectedOrigin = $segment['destination_place_id'];
            $distanceTotal += (float) $segment['distance_km'];
            $durationTotal += (int) $segment['duration_minutes'];
        }
        if ($expectedOrigin !== $route->destination_place_id) {
            throw new BusinessRuleException('ROUTE_DESTINATION_MISMATCH', 'The final route segment must end at the route destination.');
        }
        if (abs($distanceTotal - $distance) > 0.01 || $durationTotal !== $duration) {
            throw new BusinessRuleException('ROUTE_TOTALS_MISMATCH', 'Route distance and duration must equal the sum of its segments.');
        }
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $after
     */
    private function history(
        Place $place,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        ?array $before,
        array $after,
        User $actor,
        ?string $reason,
    ): void {
        DB::table('place_history')->insert([
            'id' => (string) Str::ulid(),
            'place_id' => $place->id,
            'event_type' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'changed_by' => $actor->id,
            'effective_at' => now(),
        ]);
    }

    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $after
     */
    private function record(
        string $event,
        string $action,
        Model $model,
        string $organizationId,
        User $actor,
        ?UserSession $session,
        Request $request,
        ?array $before,
        array $after,
        ?string $reason = null,
    ): void {
        $subjectType = Str::snake(class_basename($model));
        $this->audit->record(
            $event.'.succeeded', 'geography', $action, 'succeeded', $subjectType, (string) $model->getKey(),
            $organizationId, $actor, $session, $reason, $before, $after, request: $request,
        );
        $recordVersion = (int) ($model->getAttribute('record_version') ?? 1);
        $this->outbox->enqueue(
            $event, $subjectType, (string) $model->getKey(),
            ['id' => $model->getKey(), 'organization_id' => $organizationId, 'record_version' => $recordVersion],
            $event.':'.$model->getKey().':'.$recordVersion,
            $organizationId,
            $request->attributes->get('correlation_id'),
            $request->attributes->get('causation_id'),
        );
    }
}
