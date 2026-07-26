<?php

namespace App\Organization\Models;

final class OrganizationTypeRule extends OrganizationModel
{
    protected $casts = [
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
