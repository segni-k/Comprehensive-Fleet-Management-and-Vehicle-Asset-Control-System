<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RoleAssignmentScopeGrant extends IdentityModel
{
    protected $fillable = [
        'assignment_id',
        'grant_type',
        'organization_id',
        'resource_type',
        'resource_id',
    ];

    /** @return BelongsTo<UserRoleAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(UserRoleAssignment::class, 'assignment_id');
    }
}
