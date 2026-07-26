<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\ConflictException;
use App\Http\Requests\Organization\StoreOrganizationTypeRequest;
use App\Organization\Models\OrganizationType;
use App\Organization\Models\OrganizationTypeRule;
use App\Organization\Services\OrganizationAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationTypeController extends OrganizationApiController
{
    public function __construct(private readonly OrganizationAuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = OrganizationType::query();
        if ($request->filled('filter.status')) {
            $query->where('status', $request->string('filter.status'));
        }

        return $this->respond($request, $query->orderBy('sort_order')->limit($request->integer('page_size', 25))->get());
    }

    public function store(StoreOrganizationTypeRequest $request): JsonResponse
    {
        $type = DB::transaction(function () use ($request): OrganizationType {
            $type = OrganizationType::query()->create([
                ...$request->validated(),
                'status' => 'inactive',
                'configuration_status' => 'draft',
            ]);
            $type->refresh();
            $this->audit->record('organization.type.created', 'organization_type', $type->id, $this->actor($request), reason: 'Type configuration created', after: $type->toArray(), correlationId: $request->attributes->get('correlation_id'));

            return $type;
        });

        return $this->respond($request, $type, 201);
    }

    public function show(Request $request, OrganizationType $organizationType): JsonResponse
    {
        return $this->respond($request, $organizationType);
    }

    public function update(Request $request, OrganizationType $organizationType): JsonResponse
    {
        $validated = $request->validate([
            'name_key' => ['sometimes', 'string', 'max:190'],
            'translations' => ['sometimes', 'array:en,om,am'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'between:0,10000'],
            'may_be_root' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
        ]);
        $effectiveFrom = CarbonImmutable::parse($validated['effective_from']);
        if ($effectiveFrom->lessThanOrEqualTo(CarbonImmutable::parse($organizationType->effective_from))) {
            throw new ConflictException('INVALID_EFFECTIVE_DATE', 'A superseding type version must start after the current version');
        }

        $newVersion = DB::transaction(function () use ($request, $organizationType, $validated, $effectiveFrom): OrganizationType {
            $current = OrganizationType::query()->whereKey($organizationType->id)->lockForUpdate()->firstOrFail();
            $expected = $this->expectedVersion($request);
            if ((int) $current->record_version !== $expected || $current->effective_to !== null) {
                throw new ConflictException('STALE_RECORD_VERSION', 'Organization type version is stale or already superseded');
            }

            $before = $current->toArray();
            $current->forceFill([
                'effective_to' => $effectiveFrom,
                'configuration_status' => 'superseded',
                'record_version' => $expected + 1,
            ])->save();

            $newVersion = OrganizationType::query()->create([
                'code' => $current->code,
                'name_key' => $validated['name_key'] ?? $current->name_key,
                'translations' => $validated['translations'] ?? $current->translations,
                'description' => $validated['description'] ?? $current->description,
                'sort_order' => $validated['sort_order'] ?? $current->sort_order,
                'may_be_root' => $validated['may_be_root'] ?? $current->may_be_root,
                'status' => 'inactive',
                'configuration_status' => 'draft',
                'effective_from' => $effectiveFrom,
            ]);
            $newVersion->refresh();
            $this->audit->record('organization.type.superseded', 'organization_type', $newVersion->id, $this->actor($request), reason: 'Type configuration superseded', before: $before, after: $newVersion->toArray(), correlationId: $request->attributes->get('correlation_id'));

            return $newVersion;
        });

        return $this->respond($request, $newVersion);
    }

    public function changeStatus(Request $request, OrganizationType $organizationType, string $status): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'effective_at' => ['required', 'date'],
        ]);
        $expected = $this->expectedVersion($request);
        if ((int) $organizationType->record_version !== $expected) {
            throw new ConflictException('STALE_RECORD_VERSION', 'Organization type version is stale');
        }
        $effectiveAt = CarbonImmutable::parse($validated['effective_at']);
        if ($effectiveAt->isFuture()) {
            DB::transaction(function () use ($request, $organizationType, $status, $effectiveAt, $validated): void {
                $exists = DB::table('organization_status_transitions')
                    ->where('subject_type', 'organization_type')
                    ->where('subject_id', $organizationType->id)
                    ->where('status', 'scheduled')
                    ->lockForUpdate()
                    ->exists();
                if ($exists) {
                    throw new ConflictException('STATUS_TRANSITION_ALREADY_SCHEDULED', 'A status transition is already scheduled');
                }
                $transitionId = (string) Str::ulid();
                DB::table('organization_status_transitions')->insert([
                    'id' => $transitionId,
                    'subject_type' => 'organization_type',
                    'subject_id' => $organizationType->id,
                    'target_status' => $status,
                    'effective_at' => $effectiveAt,
                    'status' => 'scheduled',
                    'requested_by' => $this->actor($request),
                    'reason' => $validated['reason'],
                    'record_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->audit->record(
                    "organization.type.{$status}.scheduled",
                    'organization_status_transition',
                    $transitionId,
                    $this->actor($request),
                    reason: $validated['reason'],
                    after: ['target_status' => $status, 'effective_at' => $effectiveAt->toISOString()],
                    correlationId: $request->attributes->get('correlation_id'),
                );
            }, 3);

            return $this->respond($request, [
                'status' => 'scheduled',
                'effective_at' => $effectiveAt->toISOString(),
                'approval_required' => false,
                'blockers' => [],
            ]);
        }
        $updated = OrganizationType::query()->whereKey($organizationType->id)->where('record_version', $expected)->update([
            'status' => $status,
            'configuration_status' => $status === 'active' ? 'approved' : 'retired',
            'record_version' => $expected + 1,
        ]);
        if ($updated !== 1) {
            throw new ConflictException('STALE_RECORD_VERSION', 'Organization type version is stale');
        }
        $organizationType->refresh();
        $this->audit->record("organization.type.{$status}", 'organization_type', $organizationType->id, $this->actor($request), reason: $validated['reason'], after: $organizationType->toArray(), correlationId: $request->attributes->get('correlation_id'));

        return $this->respond($request, ['status' => 'applied', 'effective_at' => $validated['effective_at'], 'approval_required' => false, 'blockers' => []]);
    }

    public function activate(Request $request, OrganizationType $organizationType): JsonResponse
    {
        return $this->changeStatus($request, $organizationType, 'active');
    }

    public function deactivate(Request $request, OrganizationType $organizationType): JsonResponse
    {
        return $this->changeStatus($request, $organizationType, 'inactive');
    }

    public function history(Request $request, OrganizationType $organizationType): JsonResponse
    {
        return $this->respond($request, DB::table('organization_hierarchy_change_history')->where('subject_type', 'organization_type')->where('subject_id', $organizationType->id)->orderByDesc('occurred_at')->get());
    }

    public function listRules(Request $request): JsonResponse
    {
        return $this->respond($request, OrganizationTypeRule::query()->orderBy('effective_from')->get());
    }

    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_type_id' => ['required', 'string', 'size:26', 'different:child_type_id', 'exists:organization_types,id'],
            'child_type_id' => ['required', 'string', 'size:26', 'exists:organization_types,id'],
            'status' => ['required', 'in:inactive,active'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $overlap = OrganizationTypeRule::query()
            ->where('parent_type_id', $validated['parent_type_id'])
            ->where('child_type_id', $validated['child_type_id'])
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $validated['effective_from']))
            ->when($validated['effective_to'] ?? null, fn ($query, $end) => $query->where('effective_from', '<', $end))
            ->exists();
        if ($overlap) {
            throw new ConflictException('EFFECTIVE_DATE_OVERLAP', 'Organization type rule overlaps an existing version');
        }
        $rule = OrganizationTypeRule::query()->create($validated);
        $rule->refresh();
        $this->audit->record('organization.type_rule.created', 'organization_type_rule', $rule->id, $this->actor($request), reason: 'Parent-child configuration created', after: $rule->toArray(), correlationId: $request->attributes->get('correlation_id'));

        return $this->respond($request, $rule, 201);
    }
}
