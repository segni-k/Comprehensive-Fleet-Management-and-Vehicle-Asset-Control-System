<?php

namespace App\Organization\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Organization extends OrganizationModel
{
    protected $casts = [
        'name' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];

    /** @return BelongsTo<OrganizationType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(OrganizationType::class, 'type_id');
    }

    /** @return HasMany<OrganizationHierarchyEdge, $this> */
    public function childEdges(): HasMany
    {
        return $this->hasMany(OrganizationHierarchyEdge::class, 'parent_id');
    }

    /** @return HasMany<OrganizationHierarchyEdge, $this> */
    public function parentEdges(): HasMany
    {
        return $this->hasMany(OrganizationHierarchyEdge::class, 'child_id');
    }
}
