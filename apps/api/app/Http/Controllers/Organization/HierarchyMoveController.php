<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Http\Requests\Organization\HierarchyMovePreviewRequest;
use App\Organization\Models\HierarchyMoveRequest;
use App\Organization\Services\HierarchyService;
use App\Organization\Services\OrganizationAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HierarchyMoveController extends OrganizationApiController
{
    public function __construct(
        private readonly HierarchyService $hierarchy,
        private readonly OrganizationAuditService $audit,
    ) {}

    public function storePreview(HierarchyMovePreviewRequest $request): JsonResponse
    {
        $data = $request->validated();
        $preview = $this->hierarchy->createPreview(
            $data['source_organization_id'],
            $data['proposed_parent_organization_id'],
            CarbonImmutable::parse($data['requested_effective_at']),
            $data['reason'],
            $this->actor($request),
            $request->attributes->get('correlation_id'),
        );

        return $this->respond($request, $preview, 201);
    }

    public function showPreview(Request $request, string $previewId): JsonResponse
    {
        $preview = DB::table('organization_hierarchy_move_previews')->where('id', $previewId)->firstOrFail();
        $preview->snapshot = json_decode((string) $preview->snapshot, true, flags: JSON_THROW_ON_ERROR);

        return $this->respond($request, $preview);
    }

    public function cancelPreview(Request $request, string $previewId): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $expected = $this->expectedVersion($request);
        $updated = DB::table('organization_hierarchy_move_previews')->where('id', $previewId)->where('record_version', $expected)->where('status', 'active')->update([
            'status' => 'cancelled',
            'record_version' => $expected + 1,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw new ConflictException('STALE_OR_INACTIVE_PREVIEW', 'Hierarchy preview is stale or inactive');
        }
        $this->audit->record('organization.hierarchy.preview.cancelled', 'hierarchy_move_preview', $previewId, $this->actor($request), reason: $validated['reason'], correlationId: $request->attributes->get('correlation_id'));

        return $this->respond($request, DB::table('organization_hierarchy_move_previews')->where('id', $previewId)->first());
    }

    public function index(Request $request): JsonResponse
    {
        $query = HierarchyMoveRequest::query();
        if ($request->filled('filter.status')) {
            $query->where('approval_status', $request->input('filter.status'));
        }

        return $this->respond($request, $query->orderByDesc('created_at')->limit($request->integer('page_size', 25))->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preview_id' => ['required', 'string', 'size:26', 'exists:organization_hierarchy_move_previews,id'],
            'preview_version' => ['required', 'integer', 'min:1'],
            'requested_effective_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $move = DB::transaction(function () use ($request, $validated): HierarchyMoveRequest {
            $preview = DB::table('organization_hierarchy_move_previews')->where('id', $validated['preview_id'])->lockForUpdate()->first();
            if ($preview === null || $preview->status !== 'active' || CarbonImmutable::parse($preview->expires_at)->isPast()) {
                throw new ConflictException('PREVIEW_EXPIRED', 'Hierarchy move preview is unavailable or expired');
            }
            if ((int) $preview->preview_version !== (int) $validated['preview_version']) {
                throw new ConflictException('STALE_PREVIEW', 'Hierarchy move preview version is stale');
            }
            $treeVersion = (int) DB::table('organization_hierarchy_edges')->max('record_version');
            if ((int) $preview->tree_version !== $treeVersion) {
                throw new ConflictException('HIERARCHY_CHANGED', 'Hierarchy changed after preview');
            }
            if (json_decode((string) $preview->snapshot, true, flags: JSON_THROW_ON_ERROR)['blockers'] !== []) {
                throw new BusinessRuleException('PREVIEW_BLOCKED', 'Hierarchy move preview contains blockers');
            }
            $move = HierarchyMoveRequest::query()->create([
                ...$validated,
                'source_organization_id' => $preview->source_organization_id,
                'proposed_parent_id' => $preview->proposed_parent_id,
                'approval_status' => 'pending',
                'maker_checker_required' => true,
                'application_status' => 'not_scheduled',
                'requested_by' => $this->actor($request),
            ]);
            $move->refresh();
            $this->audit->record('organization.hierarchy.move.requested', 'hierarchy_move', $move->id, $this->actor($request), (string) $preview->source_organization_id, $validated['reason'], null, $move->toArray(), $request->attributes->get('correlation_id'));

            return $move;
        });

        return $this->respond($request, $move, 201);
    }

    public function show(Request $request, HierarchyMoveRequest $move): JsonResponse
    {
        return $this->respond($request, $move);
    }

    public function decide(Request $request, HierarchyMoveRequest $move, string $decision): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $expected = $this->expectedVersion($request);
        $actor = $this->actor($request);
        if ($decision === 'approved' && $move->requested_by === $actor) {
            throw new BusinessRuleException('MAKER_CHECKER_CONFLICT', 'Requester cannot approve their own hierarchy move');
        }
        if ($move->approval_status !== 'pending') {
            throw new ConflictException('MOVE_ALREADY_DECIDED', 'Hierarchy move has already been decided');
        }
        $updated = HierarchyMoveRequest::query()->whereKey($move->id)->where('record_version', $expected)->update([
            'approval_status' => $decision,
            'decided_by' => $actor,
            'record_version' => $expected + 1,
        ]);
        if ($updated !== 1) {
            throw new ConflictException('STALE_RECORD_VERSION', 'Hierarchy move version is stale');
        }
        $move->refresh();
        $this->audit->record("organization.hierarchy.move.{$decision}", 'hierarchy_move', $move->id, $actor, $move->source_organization_id, $validated['reason'], null, $move->toArray(), $request->attributes->get('correlation_id'));

        return $this->respond($request, $move);
    }

    public function approve(Request $request, HierarchyMoveRequest $move): JsonResponse
    {
        return $this->decide($request, $move, 'approved');
    }

    public function reject(Request $request, HierarchyMoveRequest $move): JsonResponse
    {
        return $this->decide($request, $move, 'rejected');
    }

    public function cancel(Request $request, HierarchyMoveRequest $move): JsonResponse
    {
        return $this->decide($request, $move, 'cancelled');
    }

    public function schedule(Request $request, HierarchyMoveRequest $move): JsonResponse
    {
        $validated = $request->validate([
            'effective_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $expected = $this->expectedVersion($request);
        if ($move->approval_status !== 'approved') {
            throw new BusinessRuleException('MOVE_NOT_APPROVED', 'Only approved moves can be scheduled');
        }
        $updated = HierarchyMoveRequest::query()->whereKey($move->id)->where('record_version', $expected)->update([
            'scheduled_at' => $validated['effective_at'],
            'application_status' => 'scheduled',
            'record_version' => $expected + 1,
        ]);
        if ($updated !== 1) {
            throw new ConflictException('STALE_RECORD_VERSION', 'Hierarchy move version is stale');
        }
        $move->refresh();
        $this->audit->record('organization.hierarchy.move.scheduled', 'hierarchy_move', $move->id, $this->actor($request), $move->source_organization_id, $validated['reason'], null, $move->toArray(), $request->attributes->get('correlation_id'));

        return $this->respond($request, $move);
    }

    public function apply(Request $request, HierarchyMoveRequest $move): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $result = $this->hierarchy->applyMove($move->id, $this->expectedVersion($request), $this->actor($request), $validated['reason'], $request->attributes->get('correlation_id'));

        return $this->respond($request, $result);
    }

    public function history(Request $request, HierarchyMoveRequest $move): JsonResponse
    {
        return $this->respond($request, DB::table('organization_hierarchy_change_history')->where('subject_type', 'hierarchy_move')->where('subject_id', $move->id)->orderBy('occurred_at')->get());
    }
}
