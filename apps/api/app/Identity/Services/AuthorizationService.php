<?php

namespace App\Identity\Services;

use App\Identity\Models\Delegation;
use App\Identity\Models\Permission;
use App\Identity\Models\User;
use App\Identity\Models\UserRoleAssignment;
use App\Identity\Models\UserSession;
use App\Organization\Models\OrganizationHierarchyEdge;
use Illuminate\Support\Collection;

final class AuthorizationService
{
    public function allows(
        User $user,
        string $permissionCode,
        ?string $organizationId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?UserSession $session = null,
    ): bool {
        return $this->resolveAuthority(
            $user,
            $permissionCode,
            $organizationId,
            $resourceType,
            $resourceId,
            $session,
        ) !== null;
    }

    /**
     * @return array{role_assignment_id: string, delegation_id: string|null}|null
     */
    public function resolveAuthority(
        User $user,
        string $permissionCode,
        ?string $organizationId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?UserSession $session = null,
    ): ?array {
        $permission = Permission::query()->where('code', $permissionCode)->where('status', 'active')->first();
        if ($permission === null || ($permission->requires_mfa && $session?->mfa_verified_at === null)) {
            return null;
        }

        $now = now();
        $assignments = UserRoleAssignment::query()
            ->with(['role.permissions', 'scopeGrants'])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->get()
            ->filter(fn (UserRoleAssignment $assignment): bool => $assignment->role->status === 'active'
                && $assignment->role->permissions->contains('id', $permission->id));

        $assignment = $assignments->first(fn (UserRoleAssignment $candidate): bool => $this->assignmentCovers(
            $candidate, $organizationId, $resourceType, $resourceId,
        ));
        if ($assignment instanceof UserRoleAssignment) {
            return ['role_assignment_id' => $assignment->id, 'delegation_id' => null];
        }

        if (! $permission->delegable) {
            return null;
        }

        $delegation = Delegation::query()
            ->with(['permissions', 'scopeGrants', 'sourceAssignment.role.permissions', 'sourceAssignment.scopeGrants'])
            ->where('delegatee_user_id', $user->id)
            ->where('status', 'active')
            ->where('effective_from', '<=', $now)
            ->where('effective_to', '>', $now)
            ->get()
            ->contains(function (Delegation $delegation) use ($permission, $organizationId, $resourceType, $resourceId): bool {
                return $delegation->permissions->contains('id', $permission->id)
                    && $delegation->sourceAssignment->status === 'active'
                    && $delegation->sourceAssignment->role->permissions->contains('id', $permission->id)
                    && $this->delegationCovers($delegation, $organizationId, $resourceType, $resourceId)
                    && $this->assignmentCovers($delegation->sourceAssignment, $organizationId, $resourceType, $resourceId);
            });

        return $delegation instanceof Delegation
            ? [
                'role_assignment_id' => $delegation->source_role_assignment_id,
                'delegation_id' => $delegation->id,
            ]
            : null;
    }

    private function assignmentCovers(
        UserRoleAssignment $assignment,
        ?string $organizationId,
        ?string $resourceType,
        ?string $resourceId,
    ): bool {
        if ($assignment->scope_mode === 'explicit_record') {
            return $resourceType !== null && $resourceId !== null
                && $assignment->scopeGrants->contains(
                    fn ($grant): bool => $grant->grant_type === 'record'
                        && $grant->resource_type === $resourceType
                        && $grant->resource_id === $resourceId,
                );
        }
        if ($organizationId === null) {
            return true;
        }
        if ($assignment->scope_mode === 'current_node') {
            return $assignment->organization_id === $organizationId;
        }
        if ($assignment->scope_mode === 'selected_child') {
            return $assignment->scopeGrants->contains(
                fn ($grant): bool => $grant->grant_type === 'organization' && $grant->organization_id === $organizationId,
            );
        }
        if ($assignment->scope_mode === 'node_and_descendants') {
            return $assignment->organization_id === $organizationId
                || $this->descendants($assignment->organization_id)->contains($organizationId);
        }

        return false;
    }

    private function delegationCovers(
        Delegation $delegation,
        ?string $organizationId,
        ?string $resourceType,
        ?string $resourceId,
    ): bool {
        if ($delegation->scope_mode === 'explicit_record') {
            return $delegation->scopeGrants->contains(
                fn ($grant): bool => $grant->grant_type === 'record'
                    && $grant->resource_type === $resourceType
                    && $grant->resource_id === $resourceId,
            );
        }
        if ($organizationId === null) {
            return true;
        }
        if ($delegation->scope_mode === 'current_node') {
            return $delegation->organization_id === $organizationId;
        }
        if ($delegation->scope_mode === 'selected_child') {
            return $delegation->scopeGrants->contains(
                fn ($grant): bool => $grant->grant_type === 'organization' && $grant->organization_id === $organizationId,
            );
        }
        if ($delegation->scope_mode === 'node_and_descendants') {
            return $delegation->organization_id === $organizationId
                || $this->descendants($delegation->organization_id)->contains($organizationId);
        }

        return false;
    }

    /** @return Collection<int, string> */
    private function descendants(string $root): Collection
    {
        $seen = collect();
        $frontier = collect([$root]);
        while ($frontier->isNotEmpty()) {
            $candidates = OrganizationHierarchyEdge::query()
                ->whereIn('parent_id', $frontier)
                ->where('status', 'active')
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->pluck('child_id');
            $children = collect();
            foreach ($candidates as $candidate) {
                if (! $seen->contains($candidate)) {
                    $children->push($candidate);
                }
            }
            $seen = $seen->merge($children)->unique()->values();
            $frontier = $children;
        }

        return $seen;
    }
}
