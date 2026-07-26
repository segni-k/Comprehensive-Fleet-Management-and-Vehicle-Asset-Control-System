<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Delegation extends IdentityModel
{
    protected $fillable = [
        'delegator_user_id',
        'delegatee_user_id',
        'source_assignment_id',
        'organization_id',
        'scope_mode',
        'effective_from',
        'effective_to',
        'requested_by',
        'approved_by',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
        'authority_snapshot',
        'status',
        'reason',
    ];

    protected $attributes = [
        'status' => 'pending',
        'record_version' => 1,
    ];

    protected $casts = [
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'authority_snapshot' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function delegatee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegatee_user_id');
    }

    /** @return BelongsTo<UserRoleAssignment, $this> */
    public function sourceAssignment(): BelongsTo
    {
        return $this->belongsTo(UserRoleAssignment::class, 'source_assignment_id');
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'delegation_permissions');
    }

    /** @return HasMany<DelegationScopeGrant, $this> */
    public function scopeGrants(): HasMany
    {
        return $this->hasMany(DelegationScopeGrant::class);
    }
}
