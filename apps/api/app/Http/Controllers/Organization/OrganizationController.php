<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationHierarchyEdge;
use App\Organization\Models\OrganizationType;
use App\Organization\Services\HierarchyService;
use App\Organization\Services\OrganizationAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationController extends OrganizationApiController
{
    public function __construct(
        private readonly HierarchyService $hierarchy,
        private readonly OrganizationAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Organization::query()->with('type');
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.type_id')) {
            $query->where('type_id', $request->input('filter.type_id'));
        }
        $sort = in_array($request->string('sort')->toString(), ['code', '-code', 'created_at', '-created_at'], true)
            ? $request->string('sort')->toString()
            : 'code';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        return $this->respond($request, $query->orderBy(ltrim($sort, '-'), $direction)->limit($request->integer('page_size', 25))->get());
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $organization = DB::transaction(function () use ($request, $data): Organization {
            $type = OrganizationType::query()->whereKey($data['type_id'])->firstOrFail();
            if (($data['parent_id'] ?? null) === null && ! $type->may_be_root) {
                throw new BusinessRuleException('ROOT_TYPE_NOT_ALLOWED', 'This organization type cannot be a root');
            }
            if (($data['parent_id'] ?? null) !== null) {
                $parent = Organization::query()->whereKey($data['parent_id'])->firstOrFail();
                $ruleExists = $this->hierarchy->typeRuleAllows(
                    $parent->type_id,
                    $data['type_id'],
                    CarbonImmutable::parse($data['effective_from']),
                );
                if (! $ruleExists) {
                    throw new BusinessRuleException('PARENT_CHILD_TYPE_NOT_ALLOWED', 'The parent-child type relationship is not active');
                }
            }
            $organization = Organization::query()->create([
                'type_id' => $data['type_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'effective_from' => $data['effective_from'],
            ]);
            $organization->refresh();
            if (($data['parent_id'] ?? null) !== null) {
                OrganizationHierarchyEdge::query()->create([
                    'parent_id' => $data['parent_id'],
                    'child_id' => $organization->id,
                    'status' => 'scheduled',
                    'effective_from' => $data['effective_from'],
                ]);
            }
            $this->audit->record('organization.node.created', 'organization', $organization->id, $this->actor($request), $organization->id, 'Organization node created', null, $organization->toArray(), $request->attributes->get('correlation_id'));

            return $organization;
        });

        return $this->respond($request, $organization, 201);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $organization->load('type');

        return $this->respond($request, $organization);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'array:en,om,am'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $expected = $this->expectedVersion($request);
        $before = $organization->toArray();
        $updated = Organization::query()->whereKey($organization->id)->where('record_version', $expected)->update([
            ...$validated,
            'record_version' => $expected + 1,
        ]);
        if ($updated !== 1) {
            throw new ConflictException('STALE_RECORD_VERSION', 'Organization version is stale');
        }
        $organization->refresh();
        $this->audit->record('organization.node.updated', 'organization', $organization->id, $this->actor($request), $organization->id, 'Organization node updated', $before, $organization->toArray(), $request->attributes->get('correlation_id'));

        return $this->respond($request, $organization);
    }

    public function changeStatus(Request $request, Organization $organization, string $status): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'effective_at' => ['required', 'date'],
        ]);
        $expected = $this->expectedVersion($request);
        if ((int) $organization->record_version !== $expected) {
            throw new ConflictException('STALE_RECORD_VERSION', 'Organization version is stale');
        }
        $effectiveAt = CarbonImmutable::parse($validated['effective_at']);
        if ($effectiveAt->isFuture()) {
            DB::transaction(
                fn () => $this->scheduleStatusTransition(
                    $request,
                    'organization',
                    $organization->id,
                    $status,
                    $effectiveAt,
                    $validated['reason'],
                ),
                3,
            );

            return $this->respond($request, [
                'status' => 'scheduled',
                'effective_at' => $effectiveAt->toISOString(),
                'approval_required' => false,
                'blockers' => [],
            ]);
        }
        $blockers = [];
        if ($status === 'inactive') {
            $hasActiveChildren = DB::table('organization_hierarchy_edges')
                ->where('parent_id', $organization->id)
                ->where('status', 'active')
                ->whereNull('effective_to')
                ->exists();
            if ($hasActiveChildren) {
                $blockers[] = 'ACTIVE_CHILDREN';
            }
        }
        if ($blockers !== []) {
            return $this->respond($request, ['status' => 'blocked', 'effective_at' => $validated['effective_at'], 'approval_required' => true, 'blockers' => $blockers]);
        }
        $updated = Organization::query()->whereKey($organization->id)->where('record_version', $expected)->update([
            'status' => $status,
            'record_version' => $expected + 1,
        ]);
        if ($updated !== 1) {
            throw new ConflictException('STALE_RECORD_VERSION', 'Organization version is stale');
        }
        $this->audit->record("organization.node.{$status}", 'organization', $organization->id, $this->actor($request), $organization->id, $validated['reason'], null, ['status' => $status], $request->attributes->get('correlation_id'));

        return $this->respond($request, ['status' => 'applied', 'effective_at' => $validated['effective_at'], 'approval_required' => false, 'blockers' => []]);
    }

    private function scheduleStatusTransition(
        Request $request,
        string $subjectType,
        string $subjectId,
        string $targetStatus,
        CarbonImmutable $effectiveAt,
        string $reason,
    ): void {
        $exists = DB::table('organization_status_transitions')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('status', 'scheduled')
            ->lockForUpdate()
            ->exists();
        if ($exists) {
            throw new ConflictException('STATUS_TRANSITION_ALREADY_SCHEDULED', 'A status transition is already scheduled');
        }
        $id = (string) Str::ulid();
        DB::table('organization_status_transitions')->insert([
            'id' => $id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'target_status' => $targetStatus,
            'effective_at' => $effectiveAt,
            'status' => 'scheduled',
            'requested_by' => $this->actor($request),
            'reason' => $reason,
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->record(
            "organization.node.{$targetStatus}.scheduled",
            'organization_status_transition',
            $id,
            $this->actor($request),
            $subjectId,
            $reason,
            null,
            ['target_status' => $targetStatus, 'effective_at' => $effectiveAt->toISOString()],
            $request->attributes->get('correlation_id'),
        );
    }

    public function activate(Request $request, Organization $organization): JsonResponse
    {
        return $this->changeStatus($request, $organization, 'active');
    }

    public function deactivate(Request $request, Organization $organization): JsonResponse
    {
        return $this->changeStatus($request, $organization, 'inactive');
    }

    public function tree(Request $request): JsonResponse
    {
        $at = CarbonImmutable::parse($request->input('as_of', now()));
        $organizations = Organization::query()->get()->keyBy('id');
        $edges = DB::table('organization_hierarchy_edges')
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->get();
        $children = [];
        $hasParent = [];
        foreach ($edges as $edge) {
            $children[(string) $edge->parent_id][] = (string) $edge->child_id;
            $hasParent[(string) $edge->child_id] = true;
        }
        $build = function (string $id) use (&$build, $organizations, $children): array {
            $organization = $organizations->get($id);

            return [
                ...($organization?->toArray() ?? ['id' => $id]),
                'children' => array_map($build, $children[$id] ?? []),
            ];
        };
        $roots = $organizations->keys()->reject(fn ($id) => isset($hasParent[(string) $id]))->map(fn ($id) => $build((string) $id))->values();

        return $this->respond($request, $roots);
    }

    public function relatives(Request $request, Organization $organization, string $relation): JsonResponse
    {
        $at = CarbonImmutable::parse($request->input('as_of', now()));
        $ids = $relation === 'ancestors'
            ? $this->hierarchy->ancestorIds($organization->id, $at)
            : $this->hierarchy->descendantIds($organization->id, $at);

        return $this->respond($request, Organization::query()->whereIn('id', $ids)->get());
    }

    public function ancestors(Request $request, Organization $organization): JsonResponse
    {
        return $this->relatives($request, $organization, 'ancestors');
    }

    public function descendants(Request $request, Organization $organization): JsonResponse
    {
        return $this->relatives($request, $organization, 'descendants');
    }

    public function history(Request $request, Organization $organization): JsonResponse
    {
        return $this->respond($request, DB::table('organization_hierarchy_change_history')->where('organization_id', $organization->id)->orderByDesc('occurred_at')->get());
    }

    public function readiness(Request $request): JsonResponse
    {
        $missing = [];
        if (! DB::table('organization_types')->where('status', 'active')->exists()) {
            $missing[] = 'organization_types';
        }
        if (! DB::table('organization_type_rules')->where('status', 'active')->exists()) {
            $missing[] = 'parent_child_rules';
        }
        if (! DB::table('organizations')->where('status', 'active')->exists()) {
            array_push($missing, 'initial_nodes', 'organization_codes');
        }
        if (! DB::table('organization_manager_assignments')->where('status', 'active')->exists()) {
            $missing[] = 'managers';
        }
        array_push($missing, 'move_approvers', 'activation_dates');

        return $this->respond($request, ['ready' => false, 'missing' => array_values(array_unique($missing))]);
    }
}
