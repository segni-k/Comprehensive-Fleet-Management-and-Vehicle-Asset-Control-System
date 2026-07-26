<?php

namespace App\Organization\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HierarchyService
{
    public function __construct(private readonly OrganizationAuditService $audit) {}

    /** @return array{valid: bool, warnings: list<string>, blockers: list<string>} */
    public function validateRelationship(string $childId, string $parentId, CarbonImmutable $at): array
    {
        $blockers = [];
        if ($childId === $parentId) {
            $blockers[] = 'SELF_PARENT';
        }

        $child = DB::table('organizations')->where('id', $childId)->first();
        $parent = DB::table('organizations')->where('id', $parentId)->first();
        if ($child === null || $parent === null) {
            $blockers[] = 'ORGANIZATION_NOT_FOUND';
        } elseif (! $this->typeRuleAllows((string) $parent->type_id, (string) $child->type_id, $at)) {
            $blockers[] = 'PARENT_CHILD_TYPE_NOT_ALLOWED';
        }

        if (in_array($parentId, $this->descendantIds($childId, $at), true)) {
            $blockers[] = 'HIERARCHY_CYCLE';
        }

        $overlap = DB::table('organization_hierarchy_edges')
            ->where('child_id', $childId)
            ->where('status', 'active')
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->exists();
        if ($overlap) {
            $blockers[] = 'ACTIVE_PARENT_OVERLAP';
        }

        return [
            'valid' => $blockers === [],
            'warnings' => [],
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @return list<string> */
    public function descendantIds(string $organizationId, CarbonImmutable $at): array
    {
        $visited = [];
        $frontier = [$organizationId];
        while ($frontier !== []) {
            $children = DB::table('organization_hierarchy_edges')
                ->whereIn('parent_id', $frontier)
                ->where('effective_from', '<=', $at)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
                ->pluck('child_id')
                ->map(fn ($id): string => (string) $id)
                ->all();
            $frontier = [];
            foreach ($children as $child) {
                if (! isset($visited[$child]) && $child !== $organizationId) {
                    $visited[$child] = true;
                    $frontier[] = $child;
                }
            }
        }

        return array_keys($visited);
    }

    /** @return list<string> */
    public function ancestorIds(string $organizationId, CarbonImmutable $at): array
    {
        $ancestors = [];
        $current = $organizationId;
        while (true) {
            $parent = $this->parentId($current, $at);
            if ($parent === null || isset($ancestors[$parent])) {
                break;
            }
            $ancestors[$parent] = true;
            $current = $parent;
        }

        return array_keys($ancestors);
    }

    public function parentId(string $organizationId, CarbonImmutable $at): ?string
    {
        $parent = DB::table('organization_hierarchy_edges')
            ->where('child_id', $organizationId)
            ->whereIn('status', ['active', 'ended'])
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->orderByDesc('effective_from')
            ->value('parent_id');

        return $parent === null ? null : (string) $parent;
    }

    /** @return array<string, mixed> */
    public function createPreview(
        string $sourceId,
        string $proposedParentId,
        CarbonImmutable $effectiveAt,
        string $reason,
        string $actor,
        ?string $correlationId,
    ): array {
        return DB::transaction(function () use ($sourceId, $proposedParentId, $effectiveAt, $reason, $actor, $correlationId): array {
            $validation = $this->validateRelationshipForMove($sourceId, $proposedParentId, $effectiveAt);
            $descendants = $this->descendantIds($sourceId, $effectiveAt);
            $currentParent = $this->parentId($sourceId, $effectiveAt);
            $managerCount = DB::table('organization_manager_assignments')
                ->whereIn('organization_id', array_merge([$sourceId], $descendants))
                ->where('status', 'active')
                ->count();
            $treeVersion = (int) DB::table('organization_hierarchy_edges')->max('record_version');
            $impacts = array_map(
                fn (string $id): array => ['impact_type' => 'descendant', 'subject_type' => 'organization', 'subject_id' => $id],
                $descendants,
            );
            if ($managerCount > 0) {
                $impacts[] = ['impact_type' => 'manager_assignment', 'subject_type' => 'aggregate', 'count' => $managerCount];
            }
            $warnings = $validation['warnings'];
            $warnings[] = 'PERMISSION_EXPANSION_REVIEW_REQUIRED';
            $warnings[] = 'PERMISSION_LOSS_REVIEW_REQUIRED';
            $snapshot = [
                'source_organization_id' => $sourceId,
                'current_parent_id' => $currentParent,
                'proposed_parent_id' => $proposedParentId,
                'affected_descendants' => $descendants,
                'affected_users' => [],
                'affected_manager_assignments' => $managerCount,
                'affected_role_assignments' => [],
                'affected_records_by_category' => [],
                'workflow_impact' => ['requires_reauthorization' => true],
                'configuration_impact' => ['inherited_settings_may_change' => true],
                'warnings' => array_values(array_unique($warnings)),
                'blockers' => $validation['blockers'],
                'impacts' => $impacts,
            ];
            $id = (string) Str::ulid();
            DB::table('organization_hierarchy_move_previews')->insert([
                'id' => $id,
                'source_organization_id' => $sourceId,
                'current_parent_id' => $currentParent,
                'proposed_parent_id' => $proposedParentId,
                'requested_effective_at' => $effectiveAt,
                'reason' => $reason,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'tree_version' => $treeVersion,
                'preview_version' => 1,
                'expires_at' => now()->addDay(),
                'status' => 'active',
                'record_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($impacts as $impact) {
                DB::table('organization_hierarchy_move_impacts')->insert([
                    'id' => (string) Str::ulid(),
                    'preview_id' => $id,
                    'impact_type' => $impact['impact_type'],
                    'subject_type' => $impact['subject_type'],
                    'subject_id' => $impact['subject_id'] ?? null,
                    'before_snapshot' => null,
                    'after_snapshot' => json_encode($impact, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->audit->record('organization.hierarchy.preview.created', 'hierarchy_move_preview', $id, $actor, $sourceId, $reason, null, $snapshot, $correlationId);

            return [
                'id' => $id,
                'record_version' => 1,
                'preview_version' => 1,
                'tree_version' => $treeVersion,
                'requested_effective_at' => $effectiveAt->toISOString(),
                'expires_at' => now()->addDay()->toISOString(),
                ...$snapshot,
            ];
        });
    }

    /** @return array{valid: bool, warnings: list<string>, blockers: list<string>} */
    private function validateRelationshipForMove(string $childId, string $parentId, CarbonImmutable $at): array
    {
        $blockers = [];
        if ($childId === $parentId) {
            $blockers[] = 'SELF_PARENT';
        }
        $child = DB::table('organizations')->where('id', $childId)->first();
        $parent = DB::table('organizations')->where('id', $parentId)->first();
        if ($child === null || $parent === null) {
            $blockers[] = 'ORGANIZATION_NOT_FOUND';
        } elseif (! $this->typeRuleAllows((string) $parent->type_id, (string) $child->type_id, $at)) {
            $blockers[] = 'PARENT_CHILD_TYPE_NOT_ALLOWED';
        }
        if (in_array($parentId, $this->descendantIds($childId, $at), true)) {
            $blockers[] = 'HIERARCHY_CYCLE';
        }

        return ['valid' => $blockers === [], 'warnings' => [], 'blockers' => array_values(array_unique($blockers))];
    }

    /** @return array<string, mixed> */
    public function applyMove(string $moveId, int $expectedVersion, string $actor, string $reason, ?string $correlationId): array
    {
        if (app()->environment('production') && ! config('platform.organization.production_move_application_enabled')) {
            throw new BusinessRuleException('PRODUCTION_MOVE_DISABLED', 'Production hierarchy moves require Milestone 3 and Milestone 4 controls');
        }

        return DB::transaction(function () use ($moveId, $expectedVersion, $actor, $reason, $correlationId): array {
            $move = DB::table('organization_hierarchy_move_requests')->where('id', $moveId)->lockForUpdate()->first();
            if ($move === null) {
                throw new BusinessRuleException('MOVE_NOT_FOUND', 'Hierarchy move was not found');
            }
            if ((int) $move->record_version !== $expectedVersion) {
                throw new ConflictException('STALE_RECORD_VERSION', 'Hierarchy move version is stale');
            }
            if ($move->approval_status !== 'approved' || $move->application_status !== 'scheduled') {
                throw new BusinessRuleException('MOVE_NOT_APPLICABLE', 'Hierarchy move is not approved and scheduled');
            }
            if ($move->scheduled_at === null || CarbonImmutable::parse($move->scheduled_at)->isFuture()) {
                throw new BusinessRuleException('MOVE_NOT_DUE', 'Hierarchy move is not due');
            }
            if ($move->requested_by === $actor) {
                throw new BusinessRuleException('MAKER_CHECKER_CONFLICT', 'Requester cannot apply their own hierarchy move');
            }
            $preview = DB::table('organization_hierarchy_move_previews')->where('id', $move->preview_id)->first();
            $treeVersion = (int) DB::table('organization_hierarchy_edges')->max('record_version');
            if ($preview === null || (int) $preview->tree_version !== $treeVersion) {
                throw new ConflictException('HIERARCHY_CHANGED', 'Hierarchy changed after preview');
            }
            $effectiveAt = CarbonImmutable::parse($move->scheduled_at);
            $validation = $this->validateRelationshipForMove((string) $move->source_organization_id, (string) $move->proposed_parent_id, $effectiveAt);
            if (! $validation['valid']) {
                throw new BusinessRuleException('INVALID_HIERARCHY_MOVE', implode(', ', $validation['blockers']));
            }
            DB::table('organization_hierarchy_edges')
                ->where('child_id', $move->source_organization_id)
                ->whereNull('effective_to')
                ->update(['effective_to' => $effectiveAt, 'status' => 'ended', 'record_version' => DB::raw('record_version + 1'), 'updated_at' => now()]);
            $edgeId = (string) Str::ulid();
            DB::table('organization_hierarchy_edges')->insert([
                'id' => $edgeId,
                'parent_id' => $move->proposed_parent_id,
                'child_id' => $move->source_organization_id,
                'status' => 'active',
                'effective_from' => $effectiveAt,
                'record_version' => $treeVersion + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('organization_hierarchy_move_requests')->where('id', $moveId)->update([
                'application_status' => 'applied',
                'record_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);
            $this->audit->record('organization.hierarchy.move.applied', 'hierarchy_move', $moveId, $actor, (string) $move->source_organization_id, $reason, null, ['edge_id' => $edgeId], $correlationId);

            return ['move_id' => $moveId, 'status' => 'applied', 'new_edge_id' => $edgeId, 'applied_at' => now()->toISOString()];
        }, 3);
    }

    /** @return array<string, mixed>|null */
    public function effectiveSetting(string $organizationId, string $definitionId, CarbonImmutable $at): ?array
    {
        foreach (array_merge([$organizationId], $this->ancestorIds($organizationId, $at)) as $index => $nodeId) {
            $setting = DB::table('organization_setting_values')
                ->where('organization_id', $nodeId)
                ->where('setting_definition_id', $definitionId)
                ->where('effective_from', '<=', $at)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
                ->orderByDesc('effective_from')
                ->first();
            if ($setting !== null) {
                return [
                    'setting_definition_id' => $definitionId,
                    'value' => json_decode((string) $setting->value, true, flags: JSON_THROW_ON_ERROR),
                    'source_organization_id' => $nodeId,
                    'source_setting_version' => (int) $setting->record_version,
                    'effective_from' => (string) $setting->effective_from,
                    'override_status' => $index === 0 ? 'local' : 'inherited',
                ];
            }
        }

        return null;
    }

    public function typeRuleAllows(string $parentTypeId, string $childTypeId, CarbonImmutable $at): bool
    {
        return DB::table('organization_type_rules')
            ->where('parent_type_id', $parentTypeId)
            ->where('child_type_id', $childTypeId)
            ->where('status', 'active')
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->exists();
    }
}
