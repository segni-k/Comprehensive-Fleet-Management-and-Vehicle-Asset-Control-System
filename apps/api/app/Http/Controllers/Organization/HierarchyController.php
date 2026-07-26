<?php

namespace App\Http\Controllers\Organization;

use App\Http\Requests\Organization\HierarchyValidationRequest;
use App\Organization\Services\HierarchyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HierarchyController extends OrganizationApiController
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function index(Request $request): JsonResponse
    {
        $at = CarbonImmutable::parse($request->input('as_of', now()));
        $query = DB::table('organization_hierarchy_edges')
            ->where('effective_from', '<=', $at)
            ->where(fn ($builder) => $builder->whereNull('effective_to')->orWhere('effective_to', '>', $at));

        return $this->respond($request, $query->orderBy('effective_from')->limit($request->integer('page_size', 25))->get());
    }

    public function show(Request $request, string $relationshipId): JsonResponse
    {
        $edge = DB::table('organization_hierarchy_edges')->where('id', $relationshipId)->firstOrFail();

        return $this->respond($request, $edge);
    }

    public function history(Request $request): JsonResponse
    {
        return $this->respond($request, DB::table('organization_hierarchy_change_history')->whereIn('event_type', [
            'organization.hierarchy.move.applied',
            'organization.hierarchy.edge.created',
        ])->orderByDesc('occurred_at')->limit($request->integer('page_size', 25))->get());
    }

    public function validateRelationship(HierarchyValidationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->hierarchy->validateRelationship(
            $validated['child_organization_id'],
            $validated['proposed_parent_organization_id'],
            CarbonImmutable::parse($validated['effective_at']),
        );

        return $this->respond($request, $result);
    }
}
