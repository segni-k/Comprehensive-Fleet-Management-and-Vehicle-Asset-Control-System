<?php

namespace App\Organization\Models;

final class OrganizationHierarchyEdge extends OrganizationModel
{
    protected $casts = [
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
