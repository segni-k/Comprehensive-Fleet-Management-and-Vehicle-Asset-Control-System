<?php

namespace App\Fleet\Models;

final class DriverLicenceClass extends FleetModel
{
    protected $casts = [
        'name' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
    ];
}
