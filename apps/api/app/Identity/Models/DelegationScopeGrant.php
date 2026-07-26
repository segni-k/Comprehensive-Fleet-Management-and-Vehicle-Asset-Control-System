<?php

namespace App\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DelegationScopeGrant extends IdentityModel
{
    protected $fillable = [
        'delegation_id',
        'grant_type',
        'organization_id',
        'resource_type',
        'resource_id',
    ];

    /** @return BelongsTo<Delegation, $this> */
    public function delegation(): BelongsTo
    {
        return $this->belongsTo(Delegation::class);
    }
}
