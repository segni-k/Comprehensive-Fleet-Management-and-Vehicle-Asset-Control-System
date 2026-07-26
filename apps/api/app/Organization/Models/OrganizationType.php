<?php

namespace App\Organization\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class OrganizationType extends OrganizationModel
{
    protected $casts = [
        'translations' => 'array',
        'may_be_root' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];

    /** @return HasMany<OrganizationTypeRule, $this> */
    public function childRules(): HasMany
    {
        return $this->hasMany(OrganizationTypeRule::class, 'parent_type_id');
    }
}
