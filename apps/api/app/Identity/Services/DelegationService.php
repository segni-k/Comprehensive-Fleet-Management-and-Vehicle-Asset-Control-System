<?php

namespace App\Identity\Services;

use App\Exceptions\BusinessRuleException;
use App\Identity\Models\Delegation;
use App\Identity\Models\Permission;
use App\Identity\Models\User;
use App\Identity\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;

final class DelegationService
{
    public function __construct(private readonly IdentityAuditService $audit) {}

    /**
     * @param  array<int, string>  $permissionCodes
     * @param  array<int, array<string, string|null>>  $scopeGrants
     */
    public function create(
        User $delegator,
        User $delegatee,
        UserRoleAssignment $source,
        array $permissionCodes,
        \DateTimeInterface $effectiveFrom,
        \DateTimeInterface $effectiveTo,
        string $reason,
        array $scopeGrants = [],
    ): Delegation {
        if ($delegator->is($delegatee) || $source->user_id !== $delegator->id || $source->status !== 'active') {
            throw new BusinessRuleException('IDENTITY_DELEGATION_SOURCE_INVALID', 'The delegation source is invalid.');
        }
        if ($effectiveTo <= $effectiveFrom || ($source->effective_to !== null && $effectiveTo > $source->effective_to)) {
            throw new BusinessRuleException('IDENTITY_DELEGATION_PERIOD_EXCEEDS_SOURCE', 'The delegation period exceeds source authority.');
        }
        $permissions = Permission::query()->whereIn('code', $permissionCodes)->where('delegable', true)->get();
        $sourcePermissionIds = $source->role()->with('permissions')->firstOrFail()->permissions->pluck('id');
        if ($permissions->count() !== count(array_unique($permissionCodes)) || $permissions->pluck('id')->diff($sourcePermissionIds)->isNotEmpty()) {
            throw new BusinessRuleException('IDENTITY_DELEGATION_AUTHORITY_EXCEEDED', 'Delegation cannot exceed source authority.');
        }

        return DB::transaction(function () use ($delegator, $delegatee, $source, $permissions, $effectiveFrom, $effectiveTo, $reason, $scopeGrants): Delegation {
            $delegation = Delegation::query()->create([
                'delegator_user_id' => $delegator->id,
                'delegatee_user_id' => $delegatee->id,
                'source_assignment_id' => $source->id,
                'organization_id' => $source->organization_id,
                'scope_mode' => $source->scope_mode,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'requested_by' => $delegator->id,
                'authority_snapshot' => [
                    'source_assignment_id' => $source->id,
                    'permission_codes' => $permissions->pluck('code')->all(),
                    'captured_at' => now()->toISOString(),
                ],
                'status' => 'pending',
                'reason' => $reason,
            ]);
            $delegation->permissions()->sync($permissions->pluck('id'));
            foreach ($scopeGrants as $grant) {
                $delegation->scopeGrants()->create($grant);
            }
            $this->audit->record('identity.delegation.requested', 'delegation', $delegation->id, 'pending', $delegator, null, $source->organization_id, $reason, null, $delegation->toArray(), 'high');

            return $delegation;
        });
    }

    public function approve(User $approver, Delegation $delegation, string $reason): Delegation
    {
        if (in_array($approver->id, [$delegation->requested_by, $delegation->delegatee_user_id], true)) {
            throw new BusinessRuleException('IDENTITY_MAKER_CHECKER_REQUIRED', 'The requester or delegatee cannot approve this delegation.');
        }
        if ($delegation->status !== 'pending') {
            throw new BusinessRuleException('IDENTITY_DELEGATION_NOT_PENDING', 'Only pending delegations can be approved.');
        }
        $delegation->forceFill([
            'approved_by' => $approver->id,
            'status' => 'active',
            'record_version' => $delegation->record_version + 1,
        ])->save();
        $this->audit->record('identity.delegation.approved', 'delegation', $delegation->id, 'succeeded', $approver, null, $delegation->organization_id, $reason, ['status' => 'pending'], ['status' => 'active'], 'high');

        return $delegation->refresh();
    }

    public function revoke(User $actor, Delegation $delegation, string $reason): Delegation
    {
        $delegation->forceFill([
            'status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(),
            'revocation_reason' => $reason, 'record_version' => $delegation->record_version + 1,
        ])->save();
        $this->audit->record('identity.delegation.revoked', 'delegation', $delegation->id, 'succeeded', $actor, null, $delegation->organization_id, $reason, null, ['status' => 'revoked'], 'high');

        return $delegation->refresh();
    }
}
