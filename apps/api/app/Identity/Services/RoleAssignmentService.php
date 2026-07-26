<?php

namespace App\Identity\Services;

use App\Exceptions\BusinessRuleException;
use App\Identity\Models\Role;
use App\Identity\Models\User;
use App\Identity\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;

final class RoleAssignmentService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly IdentityAuditService $audit,
    ) {
    }

    /** @param array<int, array<string, string|null>> $scopeGrants */
    public function request(
        User $actor,
        User $subject,
        Role $role,
        string $organizationId,
        string $scopeMode,
        \DateTimeInterface $effectiveFrom,
        ?\DateTimeInterface $effectiveTo,
        string $reason,
        array $scopeGrants = [],
    ): UserRoleAssignment {
        if (! in_array($scopeMode, ['current_node', 'node_and_descendants', 'selected_child', 'explicit_record'], true)) {
            throw new BusinessRuleException('IDENTITY_SCOPE_MODE_INVALID', 'The scope mode is not supported.');
        }
        if ($effectiveTo !== null && $effectiveTo <= $effectiveFrom) {
            throw new BusinessRuleException('IDENTITY_EFFECTIVE_PERIOD_INVALID', 'The effective end must follow the start.');
        }

        return DB::transaction(function () use ($actor, $subject, $role, $organizationId, $scopeMode, $effectiveFrom, $effectiveTo, $reason, $scopeGrants): UserRoleAssignment {
            $assignment = UserRoleAssignment::query()->create([
                'user_id' => $subject->id,
                'role_id' => $role->id,
                'organization_id' => $organizationId,
                'scope_mode' => $scopeMode,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'requested_by' => $actor->id,
                'assigned_by' => $actor->id,
                'assignment_authority_snapshot' => ['actor_id' => $actor->id, 'captured_at' => now()->toISOString()],
                'status' => 'pending',
                'reason' => $reason,
            ]);
            foreach ($scopeGrants as $grant) {
                $assignment->scopeGrants()->create($grant);
            }
            $this->audit->record('identity.role_assignment.requested', 'role_assignment', $assignment->id, 'pending', $actor, null, $organizationId, $reason, null, $assignment->toArray(), 'high');

            return $assignment;
        });
    }

    public function approve(User $approver, UserRoleAssignment $assignment, string $reason): UserRoleAssignment
    {
        if ($assignment->requested_by === $approver->id || $assignment->user_id === $approver->id) {
            throw new BusinessRuleException('IDENTITY_MAKER_CHECKER_REQUIRED', 'The requester or subject cannot approve this assignment.');
        }
        if ($assignment->status !== 'pending') {
            throw new BusinessRuleException('IDENTITY_ASSIGNMENT_NOT_PENDING', 'Only pending assignments can be approved.');
        }
        if (! $this->authorization->allows($approver, 'identity.role_assignment.approve', $assignment->organization_id)) {
            throw new BusinessRuleException('IDENTITY_ASSIGNMENT_AUTHORITY_INSUFFICIENT', 'The approver does not hold assignment authority.');
        }

        $assignment->forceFill([
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'status' => 'active',
            'record_version' => $assignment->record_version + 1,
        ])->save();
        $this->audit->record('identity.role_assignment.approved', 'role_assignment', $assignment->id, 'succeeded', $approver, null, $assignment->organization_id, $reason, ['status' => 'pending'], ['status' => 'active'], 'high');

        return $assignment->refresh();
    }

    public function revoke(User $actor, UserRoleAssignment $assignment, string $reason): UserRoleAssignment
    {
        if (! in_array($assignment->status, ['pending', 'active'], true)) {
            throw new BusinessRuleException('IDENTITY_ASSIGNMENT_NOT_REVOCABLE', 'The assignment is not revocable.');
        }
        $assignment->forceFill([
            'status' => 'revoked',
            'revoked_by' => $actor->id,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
            'record_version' => $assignment->record_version + 1,
        ])->save();
        $this->audit->record('identity.role_assignment.revoked', 'role_assignment', $assignment->id, 'succeeded', $actor, null, $assignment->organization_id, $reason, null, ['status' => 'revoked'], 'high');

        return $assignment->refresh();
    }
}
