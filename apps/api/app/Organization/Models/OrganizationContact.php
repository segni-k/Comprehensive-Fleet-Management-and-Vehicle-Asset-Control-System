<?php

namespace App\Organization\Models;

final class OrganizationContact extends OrganizationModel
{
    protected $casts = [
        'is_primary' => 'boolean',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
