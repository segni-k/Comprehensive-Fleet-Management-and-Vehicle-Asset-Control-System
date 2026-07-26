<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RoleApprovalAuthority extends IdentityModel
{
    protected $fillable = [
        'role_id', 'authority_code', 'resource_type', 'action', 'amount_limit', 'currency',
        'risk_ceiling', 'conditions', 'effective_from', 'effective_to', 'status', 'record_version',
    ];

    protected $casts = [
        'amount_limit' => 'decimal:4',
        'conditions' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
