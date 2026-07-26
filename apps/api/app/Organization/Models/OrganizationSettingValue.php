<?php

namespace App\Organization\Models;

final class OrganizationSettingValue extends OrganizationModel
{
    protected $casts = [
        'value' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
