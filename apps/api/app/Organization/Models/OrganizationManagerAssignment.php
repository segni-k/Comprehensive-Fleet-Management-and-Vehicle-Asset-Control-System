<?php

namespace App\Organization\Models;

final class OrganizationManagerAssignment extends OrganizationModel
{
    protected $casts = [
        'delegation_restricted' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
