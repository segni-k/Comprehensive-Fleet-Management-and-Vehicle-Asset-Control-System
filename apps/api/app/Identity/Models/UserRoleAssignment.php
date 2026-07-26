<?php

namespace App\Identity\Models;

use App\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class UserRoleAssignment extends IdentityModel
{
    protected $fillable = [
        'user_id',
        'role_id',
        'organization_id',
        'scope_mode',
        'effective_from',
        'effective_to',
        'requested_by',
        'approved_by',
        'assigned_by',
        'assignment_authority_snapshot',
        'status',
        'reason',
        'approved_at',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
    ];

    protected $attributes = [
        'status' => 'pending',
        'record_version' => 1,
    ];

    protected $casts = [
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'assignment_authority_snapshot' => 'array',
        'approved_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<RoleAssignmentScopeGrant, $this> */
    public function scopeGrants(): HasMany
    {
        return $this->hasMany(RoleAssignmentScopeGrant::class, 'assignment_id');
    }
}
